<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plans run for 30 days at a time instead of a calendar month, and the
 * small job plans post fewer jobs: Basic 1 per 30 days, Standard 3.
 *
 * The Razorpay plan ids are cleared so the next checkout of each plan makes
 * a new Razorpay plan billed every 30 days. Subscriptions already running
 * keep the Razorpay plan they were bought on.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->where('interval', 'monthly')->update([
            'interval' => '30_days',
            'razorpay_plan_id' => null,
            'razorpay_amount' => null,
        ]);

        foreach (['basic' => 1, 'standard' => 3] as $slug => $limit) {
            $plan = DB::table('plans')->where('slug', $slug)->first();

            if ($plan === null) {
                continue;
            }

            $features = json_decode((string) $plan->features, true) ?: [];
            $features['job_post_limit'] = $limit;

            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
        }
    }

    public function down(): void
    {
        DB::table('plans')->where('interval', '30_days')->update(['interval' => 'monthly']);
    }
};
