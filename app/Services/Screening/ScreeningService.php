<?php

namespace App\Services\Screening;

use App\Enums\ApplicationStatus;
use App\Enums\ScreeningCallStatus;
use App\Jobs\PlaceScreeningCall;
use App\Models\JobApplication;
use App\Models\ScreeningCall;
use App\Models\Setting;
use App\Notifications\InterviewScheduledNotification;
use App\Notifications\ScreeningCallCompleted;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The automated screening call, end to end.
 *
 * After the AI shortlists an applicant, the platform rings the worker on their
 * real phone from its own number and an agent asks two things: are you still
 * interested, and when could you come in. What comes back is a *proposal* —
 * the employer confirms it before anyone is committed, because the agent has
 * no idea what the employer's day looks like.
 *
 * The worker's number never leaves this class. The employer sees the outcome,
 * the summary and the slot; never the phone number.
 */
class ScreeningService
{
    /** Admin → Settings key: place a call automatically on auto-shortlist. */
    public const ENABLED_KEY = 'ai_screening_call_enabled';

    public function __construct(private readonly VoiceAgent $agent) {}

    public function agent(): VoiceAgent
    {
        return $this->agent;
    }

    /**
     * Whether the admin has switched automatic screening calls on. Off by
     * default: an unwanted robocall to a real worker is not undoable.
     */
    public function autoEnabled(): bool
    {
        return Setting::bool(self::ENABLED_KEY, false);
    }

    /**
     * Why this application cannot be called right now, or null when it can.
     * Returned as a string so both the API and the job can report it.
     */
    public function blocker(JobApplication $application): ?string
    {
        if (! $this->agent->configured()) {
            return 'provider_not_configured';
        }

        if ((string) config('screening.from_number') === '') {
            return 'no_caller_id';
        }

        if (in_array($application->status, [ApplicationStatus::Rejected, ApplicationStatus::Withdrawn], true)) {
            return 'application_closed';
        }

        // An interview already on the books makes the whole call pointless.
        if ($application->interview_at !== null) {
            return 'interview_already_scheduled';
        }

        if ($this->phoneFor($application) === null) {
            return 'no_phone_number';
        }

        // A standing "do not call me" outranks everything else. Required by the
        // TRAI rules on automated voice calls, and it survives new applications
        // because it lives on the worker's profile.
        if ($application->worker?->workerProfile?->screening_calls_opted_out) {
            return 'worker_opted_out';
        }

        $live = $application->screeningCalls()
            ->whereIn('status', [ScreeningCallStatus::Queued, ScreeningCallStatus::Dialing, ScreeningCallStatus::InProgress])
            ->exists();

        if ($live) {
            return 'call_in_progress';
        }

        // One conversation is enough; a worker who already told us no does not
        // get rung again.
        $spoken = $application->screeningCalls()
            ->where('status', ScreeningCallStatus::Completed)
            ->exists();

        if ($spoken) {
            return 'already_screened';
        }

        return null;
    }

    /**
     * Create the call row and dial. Returns null when the application is not
     * callable — the reason is available from {@see blocker()}.
     */
    public function start(JobApplication $application, int $attempt = 1): ?ScreeningCall
    {
        if ($this->blocker($application) !== null) {
            return null;
        }

        $application->loadMissing('job.employer.employerProfile', 'worker.workerProfile');

        $script = CallScript::for($application);

        $call = ScreeningCall::create([
            'job_application_id' => $application->id,
            'worker_id' => $application->worker_id,
            'provider' => $this->agent->name(),
            'status' => ScreeningCallStatus::Queued,
            'language' => $script->language,
            'attempt' => $attempt,
        ]);

        try {
            $providerCallId = $this->agent->place($call, $script, (string) $this->phoneFor($application));
        } catch (Throwable $e) {
            Log::error('Screening call could not be placed: '.$e->getMessage(), ['screening_call_id' => $call->id]);

            $call->update([
                'status' => ScreeningCallStatus::Failed,
                'failure_reason' => 'dial_failed',
                'ended_at' => now(),
            ]);

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
     * Apply a provider callback: record what happened and, when the worker
     * offered a slot, ask the employer to confirm it.
     *
     * Deliberately does NOT touch the application's interview fields. The
     * agent collects a preference; only {@see confirm()} books anything.
     */
    public function apply(ScreeningResult $result): ?ScreeningCall
    {
        $call = ScreeningCall::where('provider_call_id', $result->providerCallId)->first();

        if ($call === null) {
            Log::warning('Screening webhook for an unknown call.', ['provider_call_id' => $result->providerCallId]);

            return null;
        }

        // Late duplicates of a callback must not reopen a finished call.
        if ($call->status->isFinished()) {
            return $call;
        }

        $slot = $result->proposedInterviewAt;

        $call->update([
            'status' => $result->status,
            'outcome' => $result->outcome,
            // A slot in the past is a mis-transcription, not a booking.
            'proposed_interview_at' => $slot?->isFuture() ? $slot : null,
            'proposed_mode' => $result->proposedMode,
            'summary' => $result->summary,
            'transcript' => $result->transcript,
            'duration_seconds' => $result->durationSeconds,
            'failure_reason' => $result->failureReason,
            'ended_at' => now(),
        ]);

        $call->refresh()->loadMissing('application.job.employer', 'worker');

        if ($result->status->isRetryable()) {
            $this->scheduleRetry($call);

            return $call;
        }

        // Tell the employer how it went — including a flat "not interested",
        // which saves them chasing the applicant themselves.
        $employer = $call->application?->job?->employer;
        $employer?->notify(new ScreeningCallCompleted($call));

        return $call;
    }

    /**
     * The employer accepts the slot the worker proposed. This is the only path
     * that writes an interview onto the application, and it notifies the worker
     * exactly as manual scheduling does.
     */
    public function confirm(ScreeningCall $call, ?CarbonImmutable $at = null, ?string $mode = null): ?JobApplication
    {
        $at ??= $call->proposed_interview_at !== null
            ? CarbonImmutable::parse($call->proposed_interview_at)
            : null;

        $application = $call->application;

        if ($at === null || $application === null) {
            return null;
        }

        $application->update([
            'interview_at' => $at,
            'interview_mode' => $mode ?? $call->proposed_mode ?? 'phone',
            'interview_note' => $call->summary,
            // Interviewing implies shortlisted, same as manual scheduling.
            'shortlisted_at' => $application->shortlisted_at ?? now(),
        ]);

        $call->update(['employer_confirmed' => true]);

        $application->loadMissing('job.employer', 'worker.workerProfile', 'worker.kyc');
        $application->worker->notify(new InterviewScheduledNotification($application));

        return $application;
    }

    /**
     * Queue another attempt after a no-answer, up to the configured limit.
     */
    private function scheduleRetry(ScreeningCall $call): void
    {
        $max = (int) config('screening.max_attempts', 3);

        if ($call->attempt >= $max || $call->application === null) {
            return;
        }

        PlaceScreeningCall::dispatch($call->job_application_id, $call->attempt + 1)
            ->delay($this->nextCallableTime(now()->addMinutes((int) config('screening.retry_after_minutes', 90))));
    }

    /**
     * The next moment inside the permitted calling window, at or after `$from`.
     * India restricts automated voice calls to daytime hours, so a call that
     * comes due at 11pm waits for morning rather than being dropped.
     */
    public function nextCallableTime(?CarbonImmutable $from = null): CarbonImmutable
    {
        $timezone = (string) config('screening.window.timezone', 'Asia/Kolkata');
        $from = ($from ?? now())->setTimezone($timezone);

        $start = $from->setTimeFromTimeString((string) config('screening.window.start', '10:00'));
        $end = $from->setTimeFromTimeString((string) config('screening.window.end', '19:00'));

        return match (true) {
            $from->lt($start) => $start,
            $from->gt($end) => $start->addDay(),
            default => $from,
        };
    }

    /**
     * The number to dial: the worker's profile phone, else their login phone.
     */
    private function phoneFor(JobApplication $application): ?string
    {
        $worker = $application->worker;

        $phone = $worker?->workerProfile?->phone ?: $worker?->phone;

        return filled($phone) ? (string) $phone : null;
    }
}
