<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\JobListing;
use Carbon\CarbonInterface;

/**
 * Reposting: a closed or expired job copied into a new one, live today. The
 * copy is a new job post, so the plan's job-post limit applies to it
 * ({@see JobPostingGate}). The old job stays as it was, with its applicants.
 */
class JobRepost
{
    /**
     * @return JobListing|string the new live job, or why the job cannot be reposted
     */
    public static function repost(JobListing $job): JobListing|string
    {
        if ($job->published_at === null) {
            return __('A draft has never been live; publish it instead.');
        }

        $expired = $job->expires_at !== null && $job->expires_at->isPast();

        if ($job->status === JobStatus::Active && ! $expired) {
            return __('This job is still live.');
        }

        $account = $job->employer;
        $gate = JobPostingGate::evaluate($account);

        if (! $gate['allowed']) {
            return $gate['message'];
        }

        $copy = $job->replicate([
            'status', 'published_at', 'expires_at', 'views_count', 'boost_tier', 'boosted_until',
            'applicants_released', 'reposted_from_id',
        ]);

        $copy->fill([
            'status' => JobStatus::Active,
            // As long a run as the job had, from today; no end if it had none.
            'expires_at' => self::renewedExpiry($job),
            'reposted_from_id' => $job->id,
        ])->save();

        if ($gate['consumesFreePost']) {
            JobPostingGate::consumeFreePost($account);
        }

        return $copy;
    }

    private static function renewedExpiry(JobListing $job): ?CarbonInterface
    {
        if ($job->expires_at === null) {
            return null;
        }

        $ranFor = max(7, (int) ($job->published_at ?? $job->created_at)->diffInDays($job->expires_at));

        return now()->addDays($ranFor);
    }
}
