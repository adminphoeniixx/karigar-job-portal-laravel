<?php

namespace App\Services\Verification;

use App\Enums\KycStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Models\VerificationCall;
use App\Services\Screening\ScreeningResult;
use App\Services\Screening\ScreeningService;
use App\Services\Screening\VoiceAgent;
use App\Support\Verification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI calls that explain KYC to karigars and employers who have not done it.
 *
 * Who: an account whose KYC was never sent (not_started) or was turned down
 * (rejected). Nobody waiting on a review is rung, since there is nothing for
 * them to do, and nobody verification is switched off for.
 *
 * When: the admin's button, any time (held to calling hours), or the sweep,
 * once the admin switch is on, for accounts that signed up after it was
 * switched on and are still not verified a day later. Older accounts (and so
 * the seeded demo ones with made-up numbers) are only ever rung by hand.
 *
 * It uses the screening calls' phone line, agent and webhook; nothing is
 * charged to anyone's AI credits.
 */
class VerificationCalls
{
    public const ENABLED_KEY = 'ai_verification_call_enabled';

    /** When the switch last went on: only accounts made after it are called by the sweep. */
    public const SINCE_KEY = 'ai_verification_call_enabled_since';

    /** Hours after sign-up (or rejection) before the sweep rings. */
    public const DELAY_HOURS = 24;

    public function __construct(
        private readonly VoiceAgent $agent,
        private readonly ScreeningService $screening,
    ) {}

    public static function autoEnabled(): bool
    {
        return Setting::bool(self::ENABLED_KEY, false);
    }

    /**
     * Turn the automatic calls on or off. Turning them on starts the clock:
     * accounts that signed up before this moment are never rung by the sweep.
     */
    public static function setAutoEnabled(bool $on): void
    {
        if ($on && ! self::autoEnabled()) {
            Setting::set(self::SINCE_KEY, now()->toIso8601String());
        }

        Setting::set(self::ENABLED_KEY, $on ? '1' : '0');
    }

    public static function since(): ?CarbonImmutable
    {
        $since = Setting::get(self::SINCE_KEY);

        return filled($since) ? CarbonImmutable::parse($since) : null;
    }

    /**
     * What a call would explain to this account: not_started, rejected, or
     * null when there is nothing to explain.
     */
    public static function stateFor(User $user): ?string
    {
        if (! in_array($user->role, [UserRole::Worker, UserRole::Employer], true) || ! Verification::enabledFor($user)) {
            return null;
        }

        // Only the owner of an employer account can verify the business.
        if ($user->isEmployer() && $user->teamRole() !== 'owner') {
            return null;
        }

        return match ($user->kyc?->status) {
            null => VerificationCall::STATE_NOT_STARTED,
            KycStatus::Rejected => VerificationCall::STATE_REJECTED,
            default => null,
        };
    }

    /**
     * Why this account cannot be rung right now, or null when it can.
     */
    public function blocker(User $user, ?VerificationCall $ignore = null): ?string
    {
        return match (true) {
            ! $this->agent->configured() => 'not_configured',
            self::stateFor($user) === null => 'nothing_to_explain',
            $this->phoneFor($user) === null => 'no_phone',
            (bool) $user->workerProfile?->screening_calls_opted_out => 'opted_out',
            $user->verificationCalls()
                ->when($ignore, fn (Builder $q) => $q->whereKeyNot($ignore->id))
                ->whereIn('status', [ScreeningCallStatus::Scheduled, ScreeningCallStatus::Queued, ScreeningCallStatus::Dialing, ScreeningCallStatus::InProgress])
                ->exists() => 'already_calling',
            default => null,
        };
    }

    public static function blockerLabel(string $blocker): string
    {
        return match ($blocker) {
            'not_configured' => 'No voice provider is set up, so nothing can be dialled.',
            'nothing_to_explain' => 'This account is verified, waiting for review, or verification is off for it.',
            'no_phone' => 'This account has no phone number that can be dialled.',
            'opted_out' => 'This karigar has turned AI calls off.',
            'already_calling' => 'A call to this account is already booked or on the line.',
            default => 'This account cannot be called right now.',
        };
    }

    /**
     * Book a call for the next moment inside calling hours, and ring at once
     * if that moment is now.
     */
    public function call(User $user, string $source = 'admin', ?User $by = null, int $attempt = 1, ?CarbonImmutable $at = null): ?VerificationCall
    {
        if ($this->blocker($user) !== null) {
            return null;
        }

        // Back in the app's timezone before saving: Eloquent writes the wall
        // clock as given, so an IST time saved as-is lands 5½ hours late.
        $when = $this->screening->nextCallableTime($at ?? now()->toImmutable())->setTimezone(config('app.timezone'));

        $call = VerificationCall::create([
            'user_id' => $user->id,
            'kyc_state' => self::stateFor($user),
            'source' => $source,
            'requested_by' => $by?->id,
            'provider' => $this->agent->name(),
            'status' => ScreeningCallStatus::Scheduled,
            'attempt' => $attempt,
            'scheduled_at' => $when,
        ]);

        return $when->isFuture() ? $call : $this->place($call);
    }

    /**
     * Dial a booked call. Checked again first: the account may have verified,
     * or sent its KYC, since the call was booked.
     */
    public function place(VerificationCall $call): VerificationCall
    {
        $user = User::with('kyc', 'workerProfile', 'employerProfile', 'teamMembership')->find($call->user_id);
        $blocker = $user === null ? 'account_gone' : $this->blocker($user, $call);

        if ($blocker !== null) {
            $call->update(['status' => ScreeningCallStatus::Cancelled, 'failure_reason' => $blocker, 'ended_at' => now()]);

            return $call;
        }

        $state = (string) self::stateFor($user);
        $script = VerificationCallScript::for($user, $state);

        $call->update([
            'kyc_state' => $state,
            'status' => ScreeningCallStatus::Queued,
            'provider' => $this->agent->name(),
            'language' => $script->language,
        ]);

        try {
            $providerCallId = $this->agent->placeScript(
                'kyc-'.$call->id,
                $script,
                VerificationCallScript::extractionSchema(),
                (string) $this->phoneFor($user),
            );
        } catch (Throwable $e) {
            Log::error('Verification call could not be placed: '.$e->getMessage(), ['verification_call_id' => $call->id]);

            $call->update(['status' => ScreeningCallStatus::Failed, 'failure_reason' => 'dial_failed', 'ended_at' => now()]);

            return $call;
        }

        $call->update([
            'provider_call_id' => $providerCallId,
            'status' => ScreeningCallStatus::Dialing,
            'started_at' => now(),
        ]);

        return $call;
    }

    /**
     * Record the agent's report. A missed or busy call is tried again later,
     * up to the screening calls' limit.
     *
     * @param  array<string, mixed>  $payload
     */
    public function apply(VerificationCall $call, ScreeningResult $result, array $payload): VerificationCall
    {
        // A late duplicate must not reopen a finished call.
        if ($call->status->isFinished()) {
            return $call;
        }

        $outcome = (string) ($payload['outcome'] ?? '');

        $call->update([
            'status' => $result->status,
            'outcome' => in_array($outcome, VerificationCall::OUTCOMES, true) ? $outcome : null,
            'summary' => $result->summary,
            'transcript' => $result->transcript,
            'duration_seconds' => $result->durationSeconds,
            'failure_reason' => $result->failureReason,
            'ended_at' => now(),
        ]);

        if ($result->status->isRetryable() && $call->attempt < (int) config('screening.max_attempts', 3)) {
            $user = User::find($call->user_id);

            if ($user !== null) {
                $this->call(
                    $user,
                    'retry',
                    attempt: $call->attempt + 1,
                    at: now()->addMinutes((int) config('screening.retry_after_minutes', 90))->toImmutable(),
                );
            }
        }

        return $call;
    }

    /**
     * Accounts the sweep should ring now: signed up after the switch went on,
     * a day old, and either never sent KYC and never rung, or turned down at
     * least a day ago and not rung since. Checked once more by blocker()
     * before each call.
     *
     * @return Collection<int, User>
     */
    public function dueForAutoCall(int $limit = 50): Collection
    {
        $since = self::since();

        if (! self::autoEnabled() || $since === null) {
            return collect();
        }

        $cutoff = now()->subHours(self::DELAY_HOURS);

        return User::query()
            ->with('kyc', 'workerProfile', 'employerProfile', 'teamMembership')
            ->whereIn('role', [UserRole::Worker->value, UserRole::Employer->value])
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', $cutoff)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q
                    ->whereDoesntHave('kyc')
                    ->whereDoesntHave('verificationCalls'))
                ->orWhereHas('kyc', fn (Builder $k) => $k
                    ->where('status', KycStatus::Rejected->value)
                    ->where('reviewed_at', '<=', $cutoff)
                    ->whereNotExists(fn ($calls) => $calls
                        ->selectRaw('1')
                        ->from('verification_calls')
                        ->whereColumn('verification_calls.user_id', 'kyc_documents.user_id')
                        ->whereColumn('verification_calls.created_at', '>=', 'kyc_documents.reviewed_at'))))
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->filter(fn (User $user) => $this->blocker($user) === null)
            ->values();
    }

    /**
     * Book the sweep's calls, then dial every booked call whose time has
     * come. What the scheduler runs.
     *
     * @return array{booked: int, placed: int}
     */
    public function run(): array
    {
        $booked = $this->dueForAutoCall()
            ->map(fn (User $user) => $this->call($user, 'auto'))
            ->filter()
            ->count();

        $due = VerificationCall::where('status', ScreeningCallStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(50)
            ->get();

        $due->each(fn (VerificationCall $call) => $this->place($call));

        return ['booked' => $booked, 'placed' => $due->count()];
    }

    /**
     * The karigar's profile phone, else the login phone, in E.164.
     */
    private function phoneFor(User $user): ?string
    {
        return ScreeningService::toE164($user->workerProfile?->phone ?: $user->phone);
    }
}
