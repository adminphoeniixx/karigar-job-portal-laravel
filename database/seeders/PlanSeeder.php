<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'price' => 499,
                'interval' => 'monthly',
                'features' => ['job_post_limit' => 5, 'contact_unlock_limit' => 20, 'contact_database_limit' => 1000, 'featured' => false],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'price' => 1499,
                'interval' => 'monthly',
                'features' => ['job_post_limit' => 25, 'contact_unlock_limit' => 100, 'contact_database_limit' => 2000, 'featured' => true],
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'price' => 4999,
                'interval' => 'monthly',
                'features' => ['job_post_limit' => 200, 'contact_unlock_limit' => 1000, 'contact_database_limit' => 10000, 'featured' => true],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
