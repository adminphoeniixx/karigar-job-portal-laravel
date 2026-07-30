<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            // "Min experience" on the Post Job screen.
            $table->unsignedSmallInteger('experience_min')->nullable()->after('vacancies');
            // Job-funnel "Views" metric.
            $table->unsignedInteger('views_count')->default(0)->after('experience_min');
            // Boost (credits-funded promotion of a job post).
            $table->string('boost_tier')->nullable()->after('views_count');
            $table->timestamp('boosted_until')->nullable()->after('boost_tier');
        });
    }

    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropColumn(['experience_min', 'views_count', 'boost_tier', 'boosted_until']);
        });
    }
};
