<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A karigar who cannot make the interview is asked on the reminder call which
 * day and time would suit instead; that answer lands here for the employer to
 * act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_reminder_calls', function (Blueprint $table) {
            $table->timestamp('proposed_interview_at')->nullable()->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('interview_reminder_calls', function (Blueprint $table) {
            $table->dropColumn('proposed_interview_at');
        });
    }
};
