<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The job details WorkIndia's post-job form asks for that ours did not
 * (2026-11-19): job type, who the job is for (education, English, age,
 * languages, documents), how far karigars may live, bonus, why a deposit is
 * charged, working days, when and where the interview is held, how soon the
 * employer must hire, and anything else they need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            // full_time | part_time
            $table->string('job_type', 20)->default('full_time')->after('category');
            // The lowest education the job takes (ReferenceData::EDUCATION_LEVELS, "or above").
            $table->string('education', 30)->nullable()->after('experience_max');
            // none | basic | good | fluent
            $table->string('english', 10)->nullable()->after('education');
            $table->unsignedTinyInteger('age_min')->nullable()->after('english');
            $table->unsignedTinyInteger('age_max')->nullable()->after('age_min');
            $table->json('languages')->nullable()->after('age_max');
            // What the karigar must have: aadhaar, bank_account, own_tools…
            $table->json('assets')->nullable()->after('languages');
            // nearby (within 10 km) | city; null = city
            $table->string('reach', 10)->nullable()->after('assets');
            // Karigars from other cities who will move for the job may apply.
            $table->boolean('relocation_allowed')->default(false)->after('reach');
            // Pay on top of the monthly salary: up to this much a month, and what it is.
            $table->decimal('incentive_max', 10, 2)->nullable()->after('wage_type');
            $table->string('incentive_type', 100)->nullable()->after('incentive_max');
            // What a fee the karigar pays is for: uniform, tools, security_deposit…
            $table->string('worker_fee_purpose', 30)->nullable()->after('worker_fee_amount');
            // Working days, and the interview's days, hours and place
            // (no address = the job address).
            $table->json('work_days')->nullable()->after('core_end');
            $table->json('interview_days')->nullable()->after('interview_mode');
            $table->string('interview_start', 5)->nullable()->after('interview_days');
            $table->string('interview_end', 5)->nullable()->after('interview_start');
            $table->string('interview_address')->nullable()->after('interview_end');
            // immediate | can_wait
            $table->string('hiring_urgency', 10)->nullable()->after('vacancies');
            $table->text('other_requirements')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropColumn([
                'job_type', 'education', 'english', 'age_min', 'age_max', 'languages', 'assets',
                'reach', 'relocation_allowed', 'incentive_max', 'incentive_type', 'worker_fee_purpose',
                'work_days', 'interview_days', 'interview_start', 'interview_end', 'interview_address',
                'hiring_urgency', 'other_requirements',
            ]);
        });
    }
};
