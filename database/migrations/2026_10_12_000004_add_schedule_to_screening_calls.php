<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI screening calls can be booked for a time: the employer picks one, or a
 * call asked for outside calling hours waits for them to open. A `scheduled`
 * row is dialled by `screening:place-due` once its time comes; the queue's
 * own delay is not used (production runs the sync queue, which ignores it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_calls', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('attempt');
            // manual | auto (after auto-shortlist) | retry | invite (database pick)
            $table->string('source', 10)->nullable()->after('scheduled_at');
            $table->foreignId('scheduled_by')->nullable()->after('source')->constrained('users')->nullOnDelete();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('screening_calls', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropConstrainedForeignId('scheduled_by');
            $table->dropColumn(['scheduled_at', 'source']);
        });
    }
};
