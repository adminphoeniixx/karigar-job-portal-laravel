<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /** The job plans from before the apna-style catalogue; hidden, kept for their subscribers. */
    private const RETIRED_JOB_PLANS = ['basic', 'standard', 'pro', 'enterprise'];

    /**
     * Job plans priced and shaped like apna's (Oct 2026): ₹699 for a single
     * job, then ₹1,949 / ₹3,649 / ₹7,099 for 3 / 6 / 13 jobs over 30 / 90 /
     * 180 days, each job live for 15 days, with apna's struck-out MRPs. Each
     * step up makes a post and an unlock cheaper. Prices are before GST.
     *
     * The database plans ("Buy Database" on the employer home) open only the
     * Worker Database: a plan lets the employer browse so many karigar
     * contacts (1,000 on Basic) and reveal the numbers of some of them each
     * period (50 on Basic). There are no bought credits; unlocks come with
     * plans only. A database plan runs on its own or next to a job plan, and
     * its unlocks are spent on database karigars first.
     *
     * Everything here is a starting point the admin edits at /admin/plans, so
     * a plan that already exists is left as the admin made it.
     */
    public function run(): void
    {
        $job = ['type' => Plan::TYPE_JOB, 'interval' => Plan::INTERVAL_DAYS, 'job_live_days' => 15];
        $database = ['type' => Plan::TYPE_DATABASE, 'interval' => Plan::INTERVAL_DAYS, 'duration_days' => 30];

        $plans = [
            [
                ...$job,
                'name' => 'Single Job',
                'slug' => 'single-job',
                'tagline' => 'Try it with one job',
                'price' => 699,
                'duration_days' => 30,
                'features' => ['job_post_limit' => 1, 'contact_unlock_limit' => 20, 'contact_database_limit' => 1000, 'featured' => false],
            ],
            [
                ...$job,
                'name' => '1 Month',
                'slug' => 'job-1-month',
                'tagline' => 'Ideal for small teams',
                'price' => 1949,
                'mrp' => 2097,
                'duration_days' => 30,
                'features' => ['job_post_limit' => 3, 'contact_unlock_limit' => 75, 'contact_database_limit' => 3000, 'featured' => false],
            ],
            [
                ...$job,
                'name' => '3 Months',
                'slug' => 'job-3-months',
                'tagline' => 'Steady hiring through the quarter',
                'price' => 3649,
                'mrp' => 4194,
                'duration_days' => 90,
                'features' => ['job_post_limit' => 6, 'contact_unlock_limit' => 175, 'contact_database_limit' => 10000, 'featured' => true],
            ],
            [
                ...$job,
                'name' => '6 Months',
                'slug' => 'job-6-months',
                'tagline' => 'Best fit for larger hiring needs',
                'price' => 7099,
                'mrp' => 9087,
                'duration_days' => 180,
                'features' => ['job_post_limit' => 13, 'contact_unlock_limit' => 400, 'contact_database_limit' => 50000, 'featured' => false],
            ],
            [
                ...$database,
                'name' => 'Database Basic',
                'slug' => 'database-basic',
                'price' => 299,
                'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 50, 'contact_database_limit' => 1000, 'featured' => false],
            ],
            [
                ...$database,
                'name' => 'Database Standard',
                'slug' => 'database-standard',
                'price' => 599,
                'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 125, 'contact_database_limit' => 3000, 'featured' => true],
            ],
            [
                ...$database,
                'name' => 'Database Pro',
                'slug' => 'database-pro',
                'price' => 999,
                'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 300, 'contact_database_limit' => 10000, 'featured' => false],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(['slug' => $plan['slug']], [...$plan, 'currency' => 'INR', 'is_active' => true]);
        }

        Plan::whereIn('slug', self::RETIRED_JOB_PLANS)->where('type', Plan::TYPE_JOB)->update(['is_active' => false]);
    }
}
