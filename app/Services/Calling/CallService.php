<?php

namespace App\Services\Calling;

use App\Enums\CallStatus;
use App\Jobs\ExpireCallSession;
use App\Models\CallSession;
use App\Models\JobApplication;
use App\Models\User;
use App\Support\Chat;
use App\Support\PushSender;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rules and lifecycle of an in-app voice call, in one place.
 *
 * Why calls exist at all: an employer can talk to an applicant without buying
 * a contact unlock, and the worker's number is never disclosed. The audio runs
 * through a CallProvider channel; this class only decides who may ring whom,
 * makes the other phone ring, and keeps the call log honest.
 *
 * Permission deliberately mirrors chat (App\Support\Chat::mayChat): if the two
 * of you can message each other, you can call each other. Nothing here spends
 * credits — the abuse limits in config/calling.php do that job instead.
 */
class CallService
{
    public function __construct(
        private readonly CallProvider $provider,
        private readonly Chat $chat,
        private readonly PushSender $push,
    ) {}

    /**
     * Start a call from `$caller` to `$counterpartId` and ring their devices.
     *
     * @param  int|null  $applicationId  The applicant screen the call was placed
     *                                   from; kept for context, ignored if it
     *                                   does not belong to this pair.
     *
     * @throws CallDenied
     */
    public function dial(User $caller, int $counterpartId, ?int $applicationId = null): CallSession
    {
        if (! $this->provider->configured()) {
            throw CallDenied::unavailable();
        }

        if ($caller->id === $counterpartId) {
            throw CallDenied::selfCall();
        }

        $callee = $this->resolveCallee($caller, $counterpartId);

        if ($callee->isSuspended()) {
            throw CallDenied::notAllowed();
        }

        // Clear out calls nobody ever hung up before deciding who is busy.
        $this->sweepStale();
        $this->assertFree($caller, $callee);
        $this->assertWithinLimits($caller, $callee);

        $call = CallSession::create([
            'caller_id' => $caller->id,
            'callee_id' => $callee->id,
            'job_application_id' => $this->resolveApplicationId($applicationId, $caller, $callee),
            'channel' => $this->provider->newChannel(),
            'status' => CallStatus::Ringing,
        ]);

        // Sent inline rather than queued: a ring that waits behind a backed-up
        // worker is a missed call.
        $this->ring($call, $caller, $callee);

        ExpireCallSession::dispatch($call->id)->delay($this->ringTimeout());

        return $call;
    }

    /**
     * The callee picks up. Returns the call so the controller can hand back
     * their join credentials.
     *
     * @throws CallDenied
     */
    public function answer(CallSession $call, User $callee): CallSession
    {
        if ($call->status !== CallStatus::Ringing) {
            throw CallDenied::notOpen();
        }

        $call->update([
            'status' => CallStatus::Answered,
            'answered_at' => now(),
        ]);

        $this->signal($call->caller, [
            'type' => 'call_answered',
            'call_id' => $call->id,
        ]);

        return $call->refresh();
    }

    /**
     * The callee declines. The caller's phone is told so it can stop ringing.
     */
    public function reject(CallSession $call, User $callee): CallSession
    {
        return $this->close($call, CallStatus::Rejected, 'declined', $call->caller);
    }

    /**
     * Either side hangs up. A hang-up while still ringing is the caller
     * cancelling, which the callee sees as a missed call.
     */
    public function hangUp(CallSession $call, User $user): CallSession
    {
        $cancelled = $call->status === CallStatus::Ringing;

        return $this->close(
            $call,
            $cancelled ? CallStatus::Missed : CallStatus::Ended,
            $cancelled ? 'cancelled' : 'hangup',
            $call->counterpartFor($user),
        );
    }

    /**
     * Nobody picked up before the ring timeout.
     */
    public function expire(CallSession $call): CallSession
    {
        if ($call->status !== CallStatus::Ringing) {
            return $call;
        }

        // Guard against the job running early — a `sync` queue ignores the
        // delay entirely and would hang up on every call the instant it
        // started ringing. sweepStale() picks up anything skipped here.
        if ($call->created_at?->gt(now()->subSeconds($this->ringTimeout()))) {
            return $call;
        }

        $closed = $this->close($call, CallStatus::Missed, 'timeout', null);

        // Both phones need to stop ringing / waiting.
        foreach ([$call->caller, $call->callee] as $party) {
            $this->signal($party, [
                'type' => 'call_ended',
                'call_id' => $call->id,
                'status' => $closed->status->value,
                'reason' => 'timeout',
            ]);
        }

        return $closed;
    }

    /**
     * Join credentials for one participant of a live call. Issued fresh each
     * time so a token never outlives the conversation by much.
     */
    public function credentialsFor(CallSession $call, User $user): CallCredentials
    {
        return $this->provider->credentials($call->channel, $user->id);
    }

    public function provider(): CallProvider
    {
        return $this->provider;
    }

    /**
     * Mark ringing calls nobody answered, and connected calls nobody ended,
     * as finished. Runs before every busy check so one crashed app cannot lock
     * a user out of calling forever.
     */
    public function sweepStale(): void
    {
        CallSession::where('status', CallStatus::Ringing)
            // A short grace period on top of the timeout, so this never races
            // the ExpireCallSession job that owns the same decision.
            ->where('created_at', '<', now()->subSeconds($this->ringTimeout() + 15))
            ->update([
                'status' => CallStatus::Missed,
                'ended_reason' => 'timeout',
                'ended_at' => now(),
            ]);

        // A call cannot outlive its join token, so anything older than the TTL
        // is over whether or not the apps said so.
        CallSession::where('status', CallStatus::Answered)
            ->where('answered_at', '<', now()->subSeconds((int) config('calling.token_ttl', 3600)))
            ->update([
                'status' => CallStatus::Ended,
                'ended_reason' => 'expired',
                'ended_at' => now(),
            ]);
    }

    /**
     * Resolve — and authorise — the person on the other end. The caller's own
     * side always comes from the token, never the request body.
     *
     * @throws CallDenied
     */
    private function resolveCallee(User $caller, int $counterpartId): User
    {
        $pair = $this->chat->participants($caller, $counterpartId);

        if ($pair === null) {
            throw CallDenied::notAllowed();
        }

        [$employerId, $workerId] = $pair;

        // Calls to an employer ring the account owner, matching how chat
        // notifications work — team members do not each get their own ring.
        $callee = User::find($caller->isWorker() ? $employerId : $workerId);

        if ($callee === null) {
            throw CallDenied::notAllowed();
        }

        return $callee;
    }

    /**
     * @throws CallDenied
     */
    private function assertFree(User $caller, User $callee): void
    {
        $ids = [$caller->id, $callee->id];

        $busy = CallSession::whereIn('status', [CallStatus::Ringing, CallStatus::Answered])
            ->where(fn (Builder $q) => $q->whereIn('caller_id', $ids)->orWhereIn('callee_id', $ids))
            ->exists();

        if ($busy) {
            throw CallDenied::busy();
        }
    }

    /**
     * @throws CallDenied
     */
    private function assertWithinLimits(User $caller, User $callee): void
    {
        $since = now()->startOfDay();

        $today = CallSession::where('caller_id', $caller->id)->where('created_at', '>=', $since);

        if ($today->clone()->count() >= (int) config('calling.limits.per_caller_daily', 50)) {
            throw CallDenied::rateLimited();
        }

        if ($today->clone()->where('callee_id', $callee->id)->count() >= (int) config('calling.limits.per_pair_daily', 5)) {
            throw CallDenied::rateLimited();
        }
    }

    /**
     * Keep the application link only when it really ties these two together.
     */
    private function resolveApplicationId(?int $applicationId, User $caller, User $callee): ?int
    {
        if ($applicationId === null) {
            return null;
        }

        $workerId = $caller->isWorker() ? $caller->id : $callee->id;
        $employerId = $caller->isWorker() ? $callee->id : $caller->employerAccount()->id;

        return JobApplication::where('id', $applicationId)
            ->where('worker_id', $workerId)
            ->whereHas('job', fn ($q) => $q->where('employer_id', $employerId))
            ->exists()
            ? $applicationId
            : null;
    }

    /**
     * Make the callee's phone ring. No join token travels in the push — the
     * app calls /answer for that, which is also what records the pick-up.
     */
    private function ring(CallSession $call, User $caller, User $callee): void
    {
        $this->signal($callee, [
            'type' => 'incoming_call',
            'call_id' => $call->id,
            'channel' => $call->channel,
            'provider' => $this->provider->name(),
            'caller_id' => $caller->id,
            'caller_name' => $caller->name,
            'caller_role' => $caller->role->value,
            'caller_avatar' => $caller->isWorker()
                ? $caller->workerProfile?->avatar_url
                : $caller->employerProfile?->logo_url,
            'job_application_id' => $call->job_application_id,
            'ring_timeout' => $this->ringTimeout(),
        ]);
    }

    /**
     * @param  array<string, string|int|null>  $data
     */
    private function signal(?User $user, array $data): void
    {
        if ($user === null) {
            return;
        }

        $this->push->sendData(
            $user->deviceTokens()->pluck('token')->all(),
            $data,
            $this->ringTimeout(),
        );
    }

    /**
     * Finish a call, record how long it ran, and tell the other phone.
     */
    private function close(CallSession $call, CallStatus $status, string $reason, ?User $notify): CallSession
    {
        if (! $call->status->isOpen()) {
            return $call;
        }

        $endedAt = now();

        $call->update([
            'status' => $status,
            'ended_reason' => $reason,
            'ended_at' => $endedAt,
            // Truncated, not rounded: a 90.9-second call lasted 90 whole
            // seconds, and rounding made the duration jitter by one.
            'duration_seconds' => $call->answered_at !== null
                ? (int) max(0, $endedAt->diffInSeconds($call->answered_at, absolute: true))
                : null,
        ]);

        $this->signal($notify, [
            'type' => 'call_ended',
            'call_id' => $call->id,
            'status' => $status->value,
            'reason' => $reason,
        ]);

        return $call->refresh();
    }

    private function ringTimeout(): int
    {
        return (int) config('calling.ring_timeout', 45);
    }
}
