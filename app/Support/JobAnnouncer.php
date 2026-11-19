<?php

namespace App\Support;

use App\Models\JobListing;
use App\Models\User;
use App\Notifications\NewJobNotification;
use Illuminate\Support\Facades\Notification;

/**
 * What happens the first time a job goes live: matching karigars are told
 * about it, and the employer gets the "your job is live" email. Shared by
 * the website, the employer app, the queued safety check and the admin's
 * approval, since a job can go live from any of them.
 */
final class JobAnnouncer
{
    public static function announce(JobListing $job): void
    {
        self::notifyWorkers($job);

        if ($account = $job->employer) {
            self::sendPostedEmail($job, $account);
        }
    }

    /**
     * Email the employer a confirmation that their job is live. Uses the
     * admin-editable "job_posted" template; no-ops if it is missing/inactive.
     */
    private static function sendPostedEmail(JobListing $job, User $account): void
    {
        TemplatedMailer::send('job_posted', $account->email, [
            'employer_name' => $account->name,
            'job_title' => $job->title,
            'job_location' => trim(implode(', ', array_filter([$job->city, $job->state]))) ?: '—',
            'action_url' => url("/employer/jobs/{$job->id}/applicants"),
        ]);
    }

    /**
     * Notify relevant workers about a newly posted job. Targets workers in the
     * same city or with an overlapping skill; falls back to all workers.
     */
    private static function notifyWorkers(JobListing $job): void
    {
        // The employer wants karigars from nearby only: those within
        // NEARBY_KM of the pin, or of its city when they have no pin, and no
        // fallback to everyone.
        if ($job->reach === 'nearby' && $job->latitude !== null && $job->longitude !== null) {
            [$distance, $bindings] = MatchingKarigars::distanceSql([(float) $job->latitude, (float) $job->longitude]);

            $nearby = User::where('role', 'worker')
                ->whereHas('workerProfile', fn ($q) => $q->where('available', true)
                    ->where(fn ($q) => $q->whereRaw("{$distance} <= ?", [...$bindings, ReferenceData::NEARBY_KM])
                        ->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
                            ->whereRaw('LOWER(city) = ?', [mb_strtolower((string) $job->city)]))))
                ->get();

            if ($nearby->isNotEmpty()) {
                Notification::send($nearby, new NewJobNotification($job));
            }

            return;
        }

        $workers = User::where('role', 'worker')
            ->whereHas('workerProfile', function ($q) use ($job) {
                $q->where('available', true)
                    ->where(function ($q) use ($job) {
                        if ($job->city) {
                            $q->orWhere('city', $job->city);
                        }
                        foreach ($job->skills ?? [] as $skill) {
                            $q->orWhereJsonContains('skills', $skill);
                        }
                    });
            })
            ->get();

        // If nobody matched on location/skill, fall back to every karigar who
        // is available for work.
        if ($workers->isEmpty()) {
            $workers = User::where('role', 'worker')
                ->whereDoesntHave('workerProfile', fn ($q) => $q->where('available', false))
                ->get();
        }

        if ($workers->isNotEmpty()) {
            Notification::send($workers, new NewJobNotification($job));
        }
    }
}
