<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employer's own say over the AI on each job: auto-shortlisting (and the
 * auto-reject that goes with it), and the screening call that follows an
 * auto-shortlist. On by default; the admin's switches still decide whether
 * either runs at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->boolean('ai_shortlist_enabled')->default(true)->after('worker_fee_amount');
            $table->boolean('ai_call_enabled')->default(true)->after('ai_shortlist_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropColumn(['ai_shortlist_enabled', 'ai_call_enabled']);
        });
    }
};
