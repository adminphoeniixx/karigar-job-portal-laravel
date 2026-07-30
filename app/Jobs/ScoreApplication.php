<?php

namespace App\Jobs;

use App\Models\JobApplication;
use App\Models\Setting;
use App\Notifications\ShortlistedNotification;
use App\Services\AiMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores one applicant against its job using {@see AiMatcher}, persists the
 * result on the application, and auto-shortlists strong matches when the admin
 * has switched auto-shortlisting on (Admin → Settings).
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

        $this->maybeAutoShortlist($application, $result['score']);
    }

    /**
     * Auto-shortlist a strong match (and notify the worker), mirroring the
     * manual shortlist. Admin-controlled: off unless the admin enabled it, and
     * only for applicants scoring at or above the admin's threshold.
     */
    private function maybeAutoShortlist(JobApplication $application, int $score): void
    {
        if (! Setting::bool(self::ENABLED_KEY, false)) {
            return;
        }

        $threshold = Setting::int(self::THRESHOLD_KEY, self::DEFAULT_THRESHOLD);

        if ($threshold <= 0 || $score < $threshold || $application->shortlisted_at !== null) {
            return;
        }

        $application->update(['shortlisted_at' => now()]);
        $application->worker->notify(new ShortlistedNotification($application));
    }
}
