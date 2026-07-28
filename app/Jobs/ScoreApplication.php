<?php

namespace App\Jobs;

use App\Models\JobApplication;
use App\Notifications\ShortlistedNotification;
use App\Services\AiMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores one applicant against its job using {@see AiMatcher}, persists the
 * result on the application, and auto-shortlists strong matches when the
 * AI_AUTO_SHORTLIST_THRESHOLD is set.
 *
 * Dispatched right after a worker applies (web + API). Runs on the queue so the
 * apply request returns instantly; the score appears once the worker processes.
 */
class ScoreApplication implements ShouldQueue
{
    use Queueable;

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
     * manual shortlist. Disabled when the threshold is 0.
     */
    private function maybeAutoShortlist(JobApplication $application, int $score): void
    {
        $threshold = (int) config('services.ai.auto_shortlist_threshold', 0);

        if ($threshold <= 0 || $score < $threshold || $application->shortlisted_at !== null) {
            return;
        }

        $application->update(['shortlisted_at' => now()]);
        $application->worker->notify(new ShortlistedNotification($application));
    }
}
