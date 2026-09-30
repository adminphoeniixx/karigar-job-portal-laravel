<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - plans.type: "job" plans post jobs and see applicants; "database" plans
     *   only open the Worker Database. An employer can hold one of each.
     * - job_listings.applicants_released: how many applicants, oldest first,
     *   the employer has been shown so far. It only grows, so a batch once
     *   shown never hides again when a decision is undone.
     * - worker_contact_unlocks.pool: which allowance paid for the unlock — the
     *   job plan's, the database plan's, or a purchased credit. Each plan's
     *   allowance renews on its own cycle, so each counts its own rows. Rows
     *   from before were all charged to the job plan.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('type', 16)->default('job')->after('slug');
        });

        Schema::table('job_listings', function (Blueprint $table) {
            $table->unsignedInteger('applicants_released')->default(0);
        });

        Schema::table('worker_contact_unlocks', function (Blueprint $table) {
            $table->string('pool', 16)->nullable()->after('source');
        });

        DB::table('worker_contact_unlocks')->whereNull('pool')->update(['pool' => 'job']);
    }

    public function down(): void
    {
        Schema::table('worker_contact_unlocks', function (Blueprint $table) {
            $table->dropColumn('pool');
        });

        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropColumn('applicants_released');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
