<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Priced 40-60% under Apna and WorkIndia (a job post there is ₹650+, a
     * database unlock ₹23+), since the craft employers here are small. Each
     * step up makes an unlock cheaper: ₹20, ₹17, ₹13, ₹10. Prices are before
     * GST; job posts and unlocks are per billing cycle, 0 job posts means
     * unlimited.
     *
     * The two database plans open only the Worker Database, for employers who
     * hire without posting or need more of it than their job plan gives. They
     * run next to a job plan, and their unlocks are spent on database
     * karigars first.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'type' => Plan::TYPE_JOB,
                'price' => 499,
                'interval' => 'monthly',
                'features' => ['job_post_limit' => 5, 'contact_unlock_limit' => 25, 'contact_database_limit' => 1000, 'featured' => false],
            ],
            [
                'name' => 'Standard',
                'slug' => 'standard',
                'type' => Plan::TYPE_JOB,
                'price' => 999,
                'interval' => 'monthly',
                'features' => ['job_post_limit' => 10, 'contact_unlock_limit' => 60, 'contact_database_limit' => 3000, 'featured' => true],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'type' => Plan::TYPE_JOB,
                'price' => 1999,
                'interval' => 'monthly',
                'features' => ['job_post_limit' => 25, 'contact_unlock_limit' => 150, 'contact_database_limit' => 10000, 'featured' => false],
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'type' => Plan::TYPE_JOB,
                'price' => 4999,
                'interval' => 'monthly',
                'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 500, 'contact_database_limit' => 50000, 'featured' => false],
            ],
            [
                'name' => 'Database Basic',
                'slug' => 'database-basic',
                'type' => Plan::TYPE_DATABASE,
                'price' => 299,
                'interval' => 'monthly',
                'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 30, 'contact_database_limit' => 2000, 'featured' => false],
            ],
            [
                'name' => 'Database Pro',
                'slug' => 'database-pro',
                'type' => Plan::TYPE_DATABASE,
                'price' => 799,
                'interval' => 'monthly',
                'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 100, 'contact_database_limit' => 10000, 'featured' => false],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
