<?php

namespace App\Jobs;

use App\Models\JobListing;
use App\Services\JobModerator;
use App\Support\JobModeration;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * The safety check on a job going live ({@see JobModerator}), run off the
 * request. Until it decides, the job is a draft marked "checking": no karigar
 * sees it and nobody is alerted. A clean job then goes live; a harmful one is
 * held for an admin ({@see JobModeration}).
 *
 * On its own queue, so a burst of job posts waits its turn behind a fixed
 * number of workers instead of tying up the web server or racing the AI
 * provider's rate limit, and never delays screening calls or emails on the
 * default queue.
 */
class CheckJobSafety implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const QUEUE = 'moderation';

    /** The model being down is retried; the last attempt falls back to the phrase list. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** A re-dispatch by `jobs:recheck-stuck` while this one is still queued is a no-op. */
    public int $uniqueFor = 1800;

    public function __construct(public int $jobListingId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->jobListingId;
    }

    public function handle(JobModerator $moderator): void
    {
        $job = JobListing::with('employer')->find($this->jobListingId);

        // Deleted while it waited, or already decided.
        if ($job === null || ! JobModeration::isChecking($job)) {
            return;
        }

        $result = $moderator->check(JobModeration::fields($job), strict: $this->strict());

        if ($result['flagged']) {
            JobModeration::hold($job, $result['reasons'], $result['method']);

            return;
        }

        JobModeration::pass($job, $result['method']);
    }

    /**
     * Something other than the model broke on every attempt: hand the job to
     * an admin rather than leave the employer looking at "Checking…".
     */
    public function failed(?Throwable $e): void
    {
        $job = JobListing::with('employer')->find($this->jobListingId);

        if ($job !== null && JobModeration::isChecking($job)) {
            JobModeration::hold($job, [__('The automatic check could not run; please review by hand.')], null);
        }
    }

    /**
     * Retry a model outage while attempts remain. The sync queue (tests, a
     * misconfigured server) cannot retry, so it never waits for the model.
     */
    private function strict(): bool
    {
        return ! $this->job instanceof SyncJob && $this->attempts() < $this->tries;
    }
}
