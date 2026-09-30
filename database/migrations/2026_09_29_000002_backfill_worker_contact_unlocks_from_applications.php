<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Unlocks now renew every billing cycle, counted from worker_contact_unlocks.
     * Applicant unlocks from before that only set a flag on the application,
     * so record one row per karigar for them. The flag has no date of its own;
     * the application's updated_at is the closest there is.
     */
    public function up(): void
    {
        $pairs = DB::table('job_applications')
            ->join('job_listings', 'job_listings.id', '=', 'job_applications.job_listing_id')
            ->where('job_applications.contact_unlocked', true)
            ->groupBy('job_listings.employer_id', 'job_applications.worker_id')
            ->selectRaw('job_listings.employer_id, job_applications.worker_id, MIN(job_applications.updated_at) as unlocked_at')
            ->get();

        foreach ($pairs as $pair) {
            DB::table('worker_contact_unlocks')->insertOrIgnore([
                'employer_id' => $pair->employer_id,
                'worker_id' => $pair->worker_id,
                'created_at' => $pair->unlocked_at ?? now(),
                'updated_at' => $pair->unlocked_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        // The rows are indistinguishable from real unlocks; leave them.
    }
};
