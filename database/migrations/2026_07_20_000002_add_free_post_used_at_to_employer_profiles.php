<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employer_profiles', function (Blueprint $table) {
            // When set, this account has already consumed its one lifetime free
            // job post. Null means the free post is still available.
            $table->timestamp('free_post_used_at')->nullable()->after('contact_quota_bonus');
        });
    }

    public function down(): void
    {
        Schema::table('employer_profiles', function (Blueprint $table) {
            $table->dropColumn('free_post_used_at');
        });
    }
};
