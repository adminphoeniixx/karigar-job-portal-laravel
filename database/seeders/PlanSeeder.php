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
        $job = ['type' => Plan::TYPE_JOB, 'interval' => Plan::INTERVAL_DAYS];
        $database = ['type' => Plan::TYPE_DATABASE, 'interval' => Plan::INTERVAL_DAYS, 'duration_days' => 30];

        // The 24 Oct 2026 catalogue. No struck-out MRP: a discount is shown
        // only against a price that was genuinely charged. Each job plan puts
        // AI call credits in the wallet with every payment.
        $plans = [
            [
                ...$job,
                'name' => 'Single Job',
                'slug' => 'single-job',
                'tagline' => 'For small shops hiring occasionally',
                'price' => 499,
                'duration_days' => 15,
                'job_live_days' => 15,
                'features' => ['job_post_limit' => 1, 'contact_unlock_limit' => 20, 'contact_database_limit' => 20, 'ai_call_credits' => 5, 'featured' => false,
                    'highlights' => ['1 job post, live for 15 days', '20 database contact unlocks', 'AI-ranked applicants', '5 AI calling credits']],
            ],
            [
                ...$job,
                'name' => 'Starter',
                'slug' => 'job-1-month',
                'tagline' => 'For boutiques and small teams',
                'price' => 999,
                'duration_days' => 30,
                'job_live_days' => 30,
                'features' => ['job_post_limit' => 1, 'contact_unlock_limit' => 50, 'contact_database_limit' => 50, 'ai_call_credits' => 10, 'featured' => false,
                    'highlights' => ['1 job post, live for 30 days', '50 database contact unlocks', 'AI-ranked applicants', '10 AI calling credits']],
            ],
            [
                ...$job,
                'name' => 'Growth',
                'slug' => 'job-6-months',
                'tagline' => 'For growing brands and manufacturers',
                'price' => 1499,
                'duration_days' => 30,
                'job_live_days' => 30,
                'features' => ['job_post_limit' => 1, 'contact_unlock_limit' => 100, 'contact_database_limit' => 100, 'ai_call_credits' => 20, 'featured' => true, 'badge' => 'Most popular',
                    'highlights' => ['1 job post, live for 30 days', '100 database contact unlocks', 'AI-ranked applicants and skill matching', '20 AI calling credits']],
            ],
            [
                ...$job,
                'name' => 'Factory',
                'slug' => 'job-3-months',
                'tagline' => 'For larger, recurring hiring needs',
                'price' => 2499,
                'duration_days' => 30,
                'job_live_days' => 30,
                'features' => ['job_post_limit' => 3, 'contact_unlock_limit' => 250, 'contact_database_limit' => 250, 'ai_call_credits' => 40, 'featured' => false,
                    'highlights' => ['3 job posts, each live for 30 days', '250 database contact unlocks', 'AI-ranked applicants and advanced filters', '40 AI calling credits']],
            ],
            [
                ...$database,
                'name' => 'Database Basic',
                'slug' => 'database-basic',
                'price' => 299,
                'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 30, 'contact_database_limit' => 1000, 'featured' => false],
            ],
            [
                ...$database,
                'name' => 'Database Standard',
                'slug' => 'database-standard',
                'price' => 599,
                'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 60, 'contact_database_limit' => 3000, 'featured' => true],
            ],
            [
                ...$database,
                'name' => 'Database Pro',
                'slug' => 'database-pro',
                'price' => 999,
                'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 100, 'contact_database_limit' => 10000, 'featured' => false],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(['slug' => $plan['slug']], [...$plan, 'currency' => 'INR', 'is_active' => true]);
        }

        Plan::whereIn('slug', self::RETIRED_JOB_PLANS)->where('type', Plan::TYPE_JOB)->update(['is_active' => false]);
    }
}
