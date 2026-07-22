<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            // When the employer accepted/rejected (or the worker withdrew) — powers
            // the accurate "Decision" step time in the application tracker.
            $table->timestamp('status_changed_at')->nullable()->after('shortlisted_at');
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropColumn('status_changed_at');
        });
    }
};
