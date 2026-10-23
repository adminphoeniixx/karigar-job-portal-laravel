<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The job safety check now runs on the queue: a job going live sits as a
 * draft with `moderation_status = checking` until the check decides. This
 * records how that check was made, "ai" (the model read it) or "phrases"
 * (the model could not be asked, only the phrase list ran), so a pass is
 * on record and the admin can see which jobs only had the basic check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->string('moderation_method', 10)->nullable()->after('moderation_note'); // ai | phrases
        });
    }

    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropColumn('moderation_method');
        });
    }
};
