<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employer app QA round, 2026-11-18: a job says which gender it is for, a
 * rotational job lists each of its shifts, and a flexible job gives its
 * working hours (per day or per week) with an optional core window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            // male | female | both; null = not said (older jobs).
            $table->string('gender', 10)->nullable()->after('experience_max');
            // Rotational: [{"start":"06:00","end":"14:00"}, …], 1st shift first.
            $table->json('shift_timings')->nullable()->after('shift_end');
            // Flexible: total hours per day or per week, and the window when
            // the karigar must be available ("HH:MM").
            $table->unsignedSmallInteger('work_hours')->nullable()->after('shift_timings');
            $table->string('work_hours_period', 4)->nullable()->after('work_hours');
            $table->string('core_start', 5)->nullable()->after('work_hours_period');
            $table->string('core_end', 5)->nullable()->after('core_start');
        });
    }

    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropColumn(['gender', 'shift_timings', 'work_hours', 'work_hours_period', 'core_start', 'core_end']);
        });
    }
};
