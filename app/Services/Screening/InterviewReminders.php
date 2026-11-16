<?php

namespace App\Services\Screening;

use App\Enums\ApplicationStatus;
use App\Enums\ScreeningCallStatus;
use App\Models\InterviewReminderCall;
use App\Models\JobApplication;
use App\Notifications\InterviewReminderCallCompleted;
use App\Notifications\InterviewReminderNotification;
use App\Services\AiCredits;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The follow-up on the day of a booked interview.
 *
 * On the morning of the interview (from 07:30 IST) the karigar and the
 * employer each get a reminder notification, once per interview time. When
 * the employer's AI call settings allow it, the karigar also gets a short AI
 * call ("it is today at four, are you coming?") at the first callable moment,
 * as long as that is still 45 minutes before the interview. The answer goes
 * to the employer, with the time the karigar asked for instead if they cannot
 * make it.
 *
 * Driven by `interviews:remind` every five minutes: it sends what is due and
 * dials the booked calls whose time has come.
 */
class InterviewReminders
{
    /** The morning starts here (IST): nobody is woken for a 4 PM interview. */
    public const FROM_HOUR = '07:30';

    /** A reminder call closer to the interview than this is not worth making. */
    public const MIN_LEAD_MINUTES = 45;

    public const MAX_ATTEMPTS = 2;

    public const RETRY_AFTER_MINUTES = 30;

    public function __construct(
        private readonly VoiceAgent $agent,
        private readonly ScreeningService $screening,
    ) {}

    /**
     * @return array{reminded: int, booked: int, placed: int}
     */
    public function run(): array
    {
        $reminded = 0;
        $booked = 0;

        foreach ($this->due() as $application) {
            $call = $this->remind($application);
            $reminded++;
            $booked += $call !== null ? 1 : 0;
        }

        $placed = 0;

        foreach ($this->dueCalls() as $call) {
            $this->place($call);
            $placed++;
        }

        return ['reminded' => $reminded, 'booked' => $booked, 'placed' => $placed];
    }

    /**
     * Interviews later today (IST) that have not had their reminder, once the
     * morning has begun. An interview re-booked after its reminder went out is
     * reminded again: confirm() clears the mark when the time changes.
     *
     * @return Collection<int, JobApplication>
     */
    public function due(): Collection
    {
        $tz = CallPreferences::timezone();
        $now = CarbonImmutable::now($tz);

        if ($now->lt($now->setTimeFromTimeString(self::FROM_HOUR))) {
            return new Collection;
        }

        // interview_at is Indian wall-clock, stored as typed (see
        // JobApplication::interviewInstant()), so the bounds are IST strings.
        return $this->open()
            ->whereNull('interview_reminded_at')
            ->where('interview_at', '>', $now->format('Y-m-d H:i:s'))
            ->where('interview_at', '<=', $now->endOfDay()->format('Y-m-d H:i:s'))
            ->with('job.employer.employerProfile', 'worker.workerProfile')
            ->orderBy('interview_at')
            ->limit(200)
            ->get();
    }

    /**
     * Send the day's reminder for one interview and, where allowed, book the
     * AI call. Returns the call, or null when no call was booked.
     */
    public function remind(JobApplication $application): ?InterviewReminderCall
    {
        $application->loadMissing('job.employer.employerProfile', 'worker.workerProfile');
        $application->update(['interview_reminded_at' => now()]);

        $application->worker?->notify(new InterviewReminderNotification($application, 'worker'));
        $application->job?->employer?->notify(new InterviewReminderNotification($application, 'employer'));

        return $this->book($application);
    }

    /**
     * Book the reminder call for the first callable moment, if the employer
     * wants one, the switches are on, there are credits, and there is still
     * time before the interview.
     */
    public function book(JobApplication $application): ?InterviewReminderCall
    {
        $job = $application->job;
        $employer = $job?->employer;

        if ($job === null || $employer === null || $application->worker === null || $application->interview_at === null) {
            return null;
        }

        if (! $this->screening->autoEnabled() || ! $job->ai_call_enabled || ! $this->agent->configured()) {
            return null;
        }

        if (! CallPreferences::for($employer)->reminderCall) {
            return null;
        }

        // A karigar who opted out of automated calls is left alone here too;
        // the notification is still theirs.
        if ($application->worker->workerProfile?->screening_calls_opted_out) {
            return null;
        }

        if (! AiCredits::for($employer)->canPlaceCall()) {
            return null;
        }

        $at = $this->screening->nextCallableTime(null, $employer);

        if (! $this->inTime($application, $at)) {
            return null;
        }

        // One reminder per interview time: a second run must not book twice.
        $existing = InterviewReminderCall::where('job_application_id', $application->id)
            ->where('interview_at', $application->interview_at)
            ->exists();

        if ($existing) {
            return null;
        }

        $call = InterviewReminderCall::create([
            'job_application_id' => $application->id,
            'worker_id' => $application->worker_id,
            'interview_at' => $application->interview_at,
            'provider' => $this->agent->name(),
            'status' => ScreeningCallStatus::Scheduled,
            'attempt' => 1,
            'scheduled_at' => $at->setTimezone(config('app.timezone')),
        ]);

        return $at->gt(now()) ? $call : $this->place($call);
    }

    /**
     * Booked calls whose time has come.
     *
     * @return Collection<int, InterviewReminderCall>
     */
    public function dueCalls(): Collection
    {
        return InterviewReminderCall::where('status', ScreeningCallStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->with('application.job.employer.employerProfile', 'application.worker.workerProfile')
            ->orderBy('scheduled_at')
            ->limit(100)
            ->get();
    }

    /**
     * Dial a booked reminder. Checked again first: the interview may have
     * moved, been cancelled, or be too close by now.
     */
    public function place(InterviewReminderCall $call): InterviewReminderCall
    {
        $application = $call->application?->loadMissing('job.employer.employerProfile', 'worker.workerProfile');

        $blocker = match (true) {
            $application === null || $application->job === null => 'application_gone',
            $application->interview_at === null || ! $application->interview_at->equalTo($call->interview_at) => 'interview_moved',
            in_array($application->status, [ApplicationStatus::Rejected, ApplicationStatus::Withdrawn], true) => 'application_closed',
            (bool) $application->worker?->workerProfile?->screening_calls_opted_out => 'worker_opted_out',
            ! $this->inTime($application, CarbonImmutable::now()) => 'too_late',
            $this->phoneFor($application) === null => 'no_phone_number',
            ! AiCredits::for($application->job->employer)->canPlaceCall() => 'no_credits',
            default => null,
        };

        if ($blocker !== null) {
            $call->update(['status' => ScreeningCallStatus::Cancelled, 'failure_reason' => $blocker, 'ended_at' => now()]);

            return $call;
        }

        $script = InterviewReminderScript::for($application);

        $call->update([
            'status' => ScreeningCallStatus::Queued,
            'provider' => $this->agent->name(),
            'language' => $script->language,
        ]);

        try {
            $providerCallId = $this->agent->placeScript(
                'remind-'.$call->id,
                $script,
                InterviewReminderScript::extractionSchema(),
                (string) $this->phoneFor($application),
            );
        } catch (Throwable $e) {
            Log::error('Interview reminder call could not be placed: '.$e->getMessage(), ['interview_reminder_call_id' => $call->id]);

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
     * Record the agent's report, charge the minutes, tell the employer, and
     * try once more after a missed call while there is time.
     *
     * @param  array<string, mixed>  $payload
     */
    public function apply(InterviewReminderCall $call, ScreeningResult $result, array $payload): InterviewReminderCall
    {
        if ($call->status->isFinished()) {
            return $call;
        }

        $outcome = (string) ($payload['outcome'] ?? '');
        $slot = $result->proposedInterviewAt;

        $call->update([
            'status' => $result->status,
            'outcome' => in_array($outcome, InterviewReminderCall::OUTCOMES, true) ? $outcome : null,
            'proposed_interview_at' => $outcome === 'reschedule' && $slot?->isFuture() ? $slot : null,
            'summary' => $result->summary,
            'transcript' => $result->transcript,
            'duration_seconds' => $result->durationSeconds,
            'failure_reason' => $result->failureReason,
            'ended_at' => now(),
        ]);

        $call->refresh()->loadMissing('application.job.employer', 'worker');
        $employer = $call->application?->job?->employer;

        if ($employer !== null) {
            try {
                AiCredits::for($employer)->chargeReminderCall($call);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // A missed call is tried once more while there is time; when it
        // cannot be, the employer hears that the karigar could not be
        // reached rather than nothing at all.
        if ($result->status->isRetryable() && $this->retry($call)) {
            return $call;
        }

        $employer?->notify(new InterviewReminderCallCompleted($call));

        return $call;
    }

    /** Book the next attempt; false when there is none to book. */
    private function retry(InterviewReminderCall $call): bool
    {
        $application = $call->application;

        if ($application === null || $call->attempt >= self::MAX_ATTEMPTS) {
            return false;
        }

        $at = $this->screening->nextCallableTime(
            CarbonImmutable::now()->addMinutes(self::RETRY_AFTER_MINUTES),
            $application->job?->employer,
        );

        if (! $this->inTime($application, $at)) {
            return false;
        }

        InterviewReminderCall::create([
            'job_application_id' => $application->id,
            'worker_id' => $call->worker_id,
            'interview_at' => $call->interview_at,
            'provider' => $this->agent->name(),
            'status' => ScreeningCallStatus::Scheduled,
            'attempt' => $call->attempt + 1,
            'scheduled_at' => $at->setTimezone(config('app.timezone')),
        ]);

        return true;
    }

    /** Whether a call at $at still leaves the lead time before the interview. */
    private function inTime(JobApplication $application, CarbonImmutable $at): bool
    {
        $interview = $application->interviewInstant();

        return $interview !== null && $at->lte($interview->subMinutes(self::MIN_LEAD_MINUTES));
    }

    /**
     * @return Builder<JobApplication>
     */
    private function open(): Builder
    {
        return JobApplication::query()
            ->whereNotNull('interview_at')
            ->whereNotIn('status', [ApplicationStatus::Rejected->value, ApplicationStatus::Withdrawn->value]);
    }

    private function phoneFor(JobApplication $application): ?string
    {
        $worker = $application->worker;

        return ScreeningService::toE164($worker?->workerProfile?->phone ?: $worker?->phone);
    }
}
