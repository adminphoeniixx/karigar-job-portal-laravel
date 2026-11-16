<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three things the employer asked for around AI calls:
 *
 * - their own rules for when applicants are rung (employer_profiles.ai_call_settings),
 * - a reminder on the morning of a booked interview (job_applications.interview_reminded_at),
 * - the short AI reminder call that goes with it (interview_reminder_calls).
 *
 * Reminder calls get their own table, like verification calls: a screening
 * call belongs to the screening of an application, has its own outcomes and
 * closes the application when the karigar says no. A reminder does none of
 * that; it shares the line, the agent and the credits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employer_profiles', function (Blueprint $table) {
            $table->json('ai_call_settings')->nullable()->after('credit_balance');
        });

        Schema::table('job_applications', function (Blueprint $table) {
            $table->timestamp('interview_reminded_at')->nullable()->after('interview_note');
        });

        Schema::create('interview_reminder_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('users')->cascadeOnDelete();
            // The interview the call is about, so a moved interview gets a
            // fresh reminder instead of the stale one.
            $table->timestamp('interview_at');

            $table->string('provider');
            $table->string('provider_call_id')->nullable()->unique();
            $table->string('status')->default('scheduled');
            $table->string('language', 10)->default('hi');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('scheduled_at')->nullable();

            // will_come | reschedule | not_coming | callback_later | unclear
            $table->string('outcome')->nullable();
            $table->text('summary')->nullable();
            $table->longText('transcript')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('credits_charged')->nullable();
            $table->timestamps();

            $table->index(['job_application_id', 'interview_at']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_reminder_calls');

        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropColumn('interview_reminded_at');
        });

        Schema::table('employer_profiles', function (Blueprint $table) {
            $table->dropColumn('ai_call_settings');
        });
    }
};
