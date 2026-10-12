<?php

namespace App\Support;

use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\User;
use App\Services\ContactUnlocks;
use WeakMap;

/**
 * What an applicant card adds about the karigar: how far they are from the
 * job's site and in which area ("2.3 km · Sanganer, Jaipur"), and how many
 * other employers unlocked their number in the Worker Database.
 *
 * A list works it out for the whole page in a few queries ({@see attach()});
 * a single applicant is worked out when first asked for. Kept beside the
 * models, never on them, so nothing extra is ever saved.
 */
final class ApplicantSignals
{
    /** @var WeakMap<JobApplication, array{location: array<string, mixed>, unlocked_by_count: int}>|null */
    private static ?WeakMap $signals = null;

    /**
     * @param  iterable<JobApplication>|JobApplication  $applications
     */
    public static function attach(iterable|JobApplication $applications, User $employer): void
    {
        $applications = collect($applications instanceof JobApplication ? [$applications] : $applications)->filter();

        if ($applications->isEmpty()) {
            return;
        }

        $jobs = JobListing::whereKey($applications->pluck('job_listing_id')->unique()->all())
            ->get(['id', 'latitude', 'longitude'])
            ->keyBy('id');
        $counts = ContactUnlocks::for($employer)->othersUnlockCounts($applications->pluck('worker_id')->filter()->all());
        $employerOrigin = false;

        foreach ($applications as $application) {
            $job = $jobs[$application->job_listing_id] ?? null;

            if ($job?->latitude !== null && $job?->longitude !== null) {
                $origin = [(float) $job->latitude, (float) $job->longitude];
            } else {
                $origin = $employerOrigin === false ? ($employerOrigin = KarigarLocation::employerOrigin($employer)) : $employerOrigin;
            }

            self::map()[$application] = [
                'location' => KarigarLocation::for($application->worker?->workerProfile, $origin),
                'unlocked_by_count' => $counts[$application->worker_id] ?? 0,
            ];
        }
    }

    /**
     * @return array{location: array<string, mixed>, unlocked_by_count: int|null}
     */
    public static function of(JobApplication $application, ?User $employer): array
    {
        if (! isset(self::map()[$application]) && $employer !== null) {
            self::attach($application, $employer);
        }

        return self::map()[$application] ?? [
            'location' => KarigarLocation::for(null, null),
            'unlocked_by_count' => null,
        ];
    }

    /**
     * @return WeakMap<JobApplication, array{location: array<string, mixed>, unlocked_by_count: int}>
     */
    private static function map(): WeakMap
    {
        return self::$signals ??= new WeakMap;
    }
}
