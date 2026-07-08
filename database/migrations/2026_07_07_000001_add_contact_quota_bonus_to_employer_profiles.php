<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employer_profiles', function (Blueprint $table) {
            // Admin-granted extra worker-database contacts, on top of the plan's quota.
            $table->unsignedInteger('contact_quota_bonus')->default(0)->after('about');
        });
    }

    public function down(): void
    {
        Schema::table('employer_profiles', function (Blueprint $table) {
            $table->dropColumn('contact_quota_bonus');
        });
    }
};
