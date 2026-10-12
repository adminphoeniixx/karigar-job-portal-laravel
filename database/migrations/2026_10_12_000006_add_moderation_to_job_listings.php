<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every job going live is checked for being harmful (a scam asking karigars
 * for money, illegal or adult work, child labour…). One that looks wrong is
 * held as a draft with `moderation_status = pending` until an admin approves
 * it (it goes live) or rejects it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->string('moderation_status', 10)->nullable()->index()->after('status'); // pending | approved | rejected
            $table->text('moderation_reasons')->nullable()->after('moderation_status');   // json list
            $table->text('moderation_note')->nullable()->after('moderation_reasons');     // admin's reason on reject
            $table->timestamp('moderated_at')->nullable()->after('moderation_note');
            $table->foreignId('moderated_by')->nullable()->after('moderated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn(['moderation_status', 'moderation_reasons', 'moderation_note', 'moderated_at']);
        });
    }
};
