<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('users')->cascadeOnDelete();

            $table->string('provider');
            // The provider's own id for this call, used to match webhooks back
            // to the row. Nullable because the row exists before we dial.
            $table->string('provider_call_id')->nullable()->unique();
            $table->string('status')->default('queued');
            $table->string('language', 10)->default('hi');
            // Which try this is. Workers on site miss the first call constantly.
            $table->unsignedTinyInteger('attempt')->default(1);

            $table->string('outcome')->nullable();
            // What the worker offered when asked "kab free ho?". Still a
            // proposal — the employer confirms it before it becomes real.
            $table->timestamp('proposed_interview_at')->nullable();
            $table->string('proposed_mode')->nullable();
            $table->boolean('employer_confirmed')->default(false);

            $table->text('summary')->nullable();
            $table->longText('transcript')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['job_application_id', 'created_at']);
            $table->index(['worker_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_calls');
    }
};
