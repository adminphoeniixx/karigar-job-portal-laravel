<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Models\JobApplication;
use App\Models\Setting;
use App\Notifications\ApplicationStatusNotification;
use App\Notifications\ShortlistedNotification;
use App\Services\AiMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores one applicant against its job using {@see AiMatcher}, persists the
 * result on the application, then — when the admin has switched them on in
 * Admin → Settings — auto-shortlists strong matches and auto-rejects poor ones.
 *
 * Dispatched right after a worker applies (web + API). Runs on the queue so the
 * apply request returns instantly; the score appears once the worker processes.
 */
class ScoreApplication implements ShouldQueue
{
    use Queueable;

    /** Admin → Settings keys driving auto-shortlisting. */
    public const ENABLED_KEY = 'ai_auto_shortlist_enabled';

    public const THRESHOLD_KEY = 'ai_auto_shortlist_threshold';

    /** Used until the admin saves a threshold of their own. */
    public const DEFAULT_THRESHOLD = 80;

    /** Admin → Settings keys driving auto-rejection of poor matches. */
    public const REJECT_ENABLED_KEY = 'ai_auto_reject_enabled';

    public const REJECT_BELOW_KEY = 'ai_auto_reject_below';

    /** Anything under this is a "weak" match to the model. */
    public const DEFAULT_REJECT_BELOW = 30;

    public int $tries = 2;

    public int $backoff = 20;

    public function __construct(public int $applicationId) {}

    public function handle(AiMatcher $matcher): void
    {
        $application = JobApplication::with('job', 'worker.workerProfile')->find($this->applicationId);

        if ($application === null || $application->job === null || $application->worker === null) {
            return;
        }

        $result = $matcher->score($application->job, $application->worker);

        $application->forceFill([
            'ai_score' => $result['score'],
            'ai_recommendation' => $result['recommendation'],
            'ai_summary' => $result['summary'],
            'ai_matched_skills' => $result['matched_skills'],
            'ai_red_flags' => $result['red_flags'],
            'ai_scored_at' => now(),
        ])->save();

        // A single application can only go one way, and a strong match wins:
        // check shortlisting first and only consider rejection if it declined.
        if (! $this->maybeAutoShortlist($application, $result['score'])) {
            $this->maybeAutoReject($application, $result['score']);
        }
    }

    /**
     * Auto-shortlist a strong match (and notify the worker), mirroring the
     * manual shortlist. Admin-controlled: off unless the admin enabled it, and
     * only for applicants scoring at or above the admin's threshold.
     */
    private function maybeAutoShortlist(JobApplication $application, int $score): bool
    {
        if (! Setting::bool(self::ENABLED_KEY, false)) {
            return false;
        }

        $threshold = Setting::int(self::THRESHOLD_KEY, self::DEFAULT_THRESHOLD);

        if ($threshold <= 0 || $score < $threshold || $application->shortlisted_at !== null) {
            return false;
        }

        $application->update(['shortlisted_at' => now()]);
        $application->worker->notify(new ShortlistedNotification($application));

        return true;
    }

    /**
     * Reject a poor match on the employer's behalf, mirroring the manual reject
     * (same status, same timestamp, same notification). Admin-controlled and off
     * by default: a wrong auto-reject costs a real worker a real job, so this
     * never touches an application the employer has already moved on.
     */
    private function maybeAutoReject(JobApplication $application, int $score): void
    {
        if (! Setting::bool(self::REJECT_ENABLED_KEY, false)) {
            return;
        }

        $below = Setting::int(self::REJECT_BELOW_KEY, self::DEFAULT_REJECT_BELOW);

        // Only ever act on an untouched application: still pending, never
        // shortlisted, no interview booked.
        $untouched = $application->status === ApplicationStatus::Pending
            && $application->shortlisted_at === null
            && $application->interview_at === null;

        if ($below <= 0 || $score >= $below || ! $untouched) {
            return;
        }

        $application->update([
            'status' => ApplicationStatus::Rejected,
            'status_changed_at' => now(),
        ]);

        $application->worker->notify(new ApplicationStatusNotification($application));
    }
}
