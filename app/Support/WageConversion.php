<?php

namespace App\Support;

use App\Models\WorkerProfile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns every stored daily or hourly wage into its monthly amount (see
 * {@see Wage}). Run by a migration, and again by `wages:to-monthly` for rows
 * written by older code in between. Running it twice changes nothing.
 */
final class WageConversion
{
    /**
     * @return array{jobs: int, applications: int, profiles: int}
     */
    public static function run(): array
    {
        $counts = ['jobs' => 0, 'applications' => 0, 'profiles' => 0];

        DB::transaction(function () use (&$counts) {
            foreach (['hourly', 'daily'] as $period) {
                $factor = Wage::factor($period);
                $jobIds = DB::table('job_listings')->where('wage_type', $period)->pluck('id');

                // Applications first: their figures are in their job's period,
                // which changes below.
                $counts['applications'] += DB::table('job_applications')
                    ->whereIn('job_listing_id', $jobIds)
                    ->where(fn ($q) => $q->whereNotNull('expected_wage')->orWhereNotNull('offered_wage'))
                    ->update([
                        'expected_wage' => DB::raw("ROUND(expected_wage * {$factor})"),
                        'offered_wage' => DB::raw("ROUND(offered_wage * {$factor})"),
                    ]);

                $counts['jobs'] += DB::table('job_listings')->where('wage_type', $period)->update([
                    'wage_min' => DB::raw("ROUND(wage_min * {$factor})"),
                    'wage_max' => DB::raw("ROUND(wage_max * {$factor})"),
                    'wage_type' => Wage::MONTHLY,
                ]);

                $counts['profiles'] += DB::table('worker_profiles')->where('wage_type', $period)->update([
                    'expected_wage' => DB::raw("ROUND(expected_wage * {$factor})"),
                    'wage_type' => Wage::MONTHLY,
                ]);
            }
        });

        return $counts;
    }

    /**
     * Refresh the karigar search index, where Find Workers filters and sorts
     * by expected wage, with the monthly figures. Only real sign-ups: the
     * seeded dummy and test karigars are kept out of search on purpose, and a
     * full `scout:import` would put them in front of real employers.
     *
     * @return int|null karigars re-synced, or null when search was unreachable
     */
    public static function syncSearch(): ?int
    {
        try {
            $profiles = WorkerProfile::whereHas('user', fn ($q) => $q
                ->where('email', 'not like', '%@dummy.karigar.test')
                ->where('email', 'not like', '%@karigar.test'))
                ->with('user')
                ->get()
                ->filter->shouldBeSearchable();

            $profiles->searchable();

            return $profiles->count();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
