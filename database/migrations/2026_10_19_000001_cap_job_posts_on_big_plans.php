<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * No job plan posts unlimited jobs any more, and the big plans post in line
 * with their price: Pro 7 per 30 days (was 25), Enterprise 20 (was unlimited).
 * Each step up still makes a post cheaper: ₹499, ₹333, ₹286, ₹250.
 */
return new class extends Migration
{
    private const LIMITS = ['pro' => [7, 25], 'enterprise' => [20, 0]];

    public function up(): void
    {
        $this->setLimits(0);
    }

    public function down(): void
    {
        $this->setLimits(1);
    }

    private function setLimits(int $which): void
    {
        foreach (self::LIMITS as $slug => $limits) {
            $plan = DB::table('plans')->where('slug', $slug)->where('type', 'job')->first();

            if ($plan === null) {
                continue;
            }

            $features = json_decode((string) $plan->features, true) ?: [];
            $features['job_post_limit'] = $limits[$which];

            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
        }
    }
};
