<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_calls', function (Blueprint $table) {
            // Path on the 'private' disk (BunnyCDN): the call's audio, both
            // voices mixed to one mono MP3. Null until the agent uploads it.
            $table->string('recording_path')->nullable()->after('transcript');
        });
    }

    public function down(): void
    {
        Schema::table('screening_calls', function (Blueprint $table) {
            $table->dropColumn('recording_path');
        });
    }
};
