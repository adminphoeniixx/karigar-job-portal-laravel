<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI calls that walk an unverified karigar or employer through KYC.
 *
 * Their own table, not rows in screening_calls: a screening call belongs to a
 * job application and charges the employer's AI credits, and neither is true
 * here. They share the phone line and the agent, nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // What the call explains: not_started (the whole process) or
            // rejected (why, and how to send it again).
            $table->string('kyc_state', 20);
            // auto (the sweep, after sign-up) | admin (the admin's button) | retry
            $table->string('source', 20)->default('auto');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('provider');
            $table->string('provider_call_id')->nullable()->unique();
            $table->string('status')->default('scheduled');
            $table->string('language', 10)->default('hi');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('scheduled_at')->nullable();

            $table->string('outcome')->nullable();
            $table->text('summary')->nullable();
            $table->longText('transcript')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_calls');
    }
};
