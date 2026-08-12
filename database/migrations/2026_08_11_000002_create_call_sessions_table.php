<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('callee_id')->constrained('users')->cascadeOnDelete();
            // The application the call came off, when the caller opened it from
            // an applicant screen. Kept for context only; the permission check
            // does not depend on it.
            $table->foreignId('job_application_id')->nullable()->constrained()->nullOnDelete();

            // The provider-side room both devices join. Random and single-use,
            // so a leaked channel name is worthless once the call ends.
            $table->string('channel')->unique();
            $table->string('status')->default('ringing');
            $table->string('ended_reason')->nullable();

            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['caller_id', 'created_at']);
            $table->index(['callee_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_sessions');
    }
};
