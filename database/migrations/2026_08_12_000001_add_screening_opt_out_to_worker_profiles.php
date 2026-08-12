<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A worker's standing "do not call me" for automated screening calls.
 *
 * TRAI's commercial-communication rules require an opt-out on automated voice
 * calls, and a worker who has said no must stay opted out even after applying
 * to a new job — so this lives on the profile, not on the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->boolean('screening_calls_opted_out')->default(false)->after('available');
        });
    }

    public function down(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->dropColumn('screening_calls_opted_out');
        });
    }
};
