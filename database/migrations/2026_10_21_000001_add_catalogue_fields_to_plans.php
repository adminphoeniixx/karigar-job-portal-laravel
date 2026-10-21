<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plans the admin can shape end to end.
 *
 * - duration_days: how long a plan runs (30, 90, 180 …), billed by Razorpay
 *   every that many days. Plans on the old '30_days' interval move to it.
 * - job_live_days: how long each job posted on the plan stays live.
 * - tagline + mrp: the "Ideal for small teams" line and the struck-out price.
 *
 * The apna-priced job plans themselves come from PlanSeeder, run after the
 * deploy: code from before this change would bill a 90-day plan every 30.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('tagline', 120)->nullable()->after('name');
            $table->decimal('mrp', 10, 2)->nullable()->after('price');
            $table->unsignedSmallInteger('duration_days')->nullable()->after('interval');
            $table->unsignedSmallInteger('job_live_days')->nullable()->after('duration_days');
        });

        DB::table('plans')->where('interval', '30_days')->update(['interval' => 'days', 'duration_days' => 30]);
    }

    public function down(): void
    {
        DB::table('plans')->where('interval', 'days')->update(['interval' => '30_days']);

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'mrp', 'duration_days', 'job_live_days']);
        });
    }
};
