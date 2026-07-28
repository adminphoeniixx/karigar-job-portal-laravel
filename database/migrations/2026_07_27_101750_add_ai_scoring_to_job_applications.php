<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            // AI match score (0-100) of this worker against the job's requirements.
            $table->unsignedTinyInteger('ai_score')->nullable()->after('contact_unlocked');
            // strong_match | good_match | maybe | weak
            $table->string('ai_recommendation')->nullable()->after('ai_score');
            // One-line human-readable reason.
            $table->string('ai_summary')->nullable()->after('ai_recommendation');
            $table->jsonb('ai_matched_skills')->nullable()->after('ai_summary');
            $table->jsonb('ai_red_flags')->nullable()->after('ai_matched_skills');
            $table->timestamp('ai_scored_at')->nullable()->after('ai_red_flags');
            // Sort applicants by best-match first.
            $table->index(['job_listing_id', 'ai_score']);
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropIndex(['job_listing_id', 'ai_score']);
            $table->dropColumn([
                'ai_score', 'ai_recommendation', 'ai_summary',
                'ai_matched_skills', 'ai_red_flags', 'ai_scored_at',
            ]);
        });
    }
};
