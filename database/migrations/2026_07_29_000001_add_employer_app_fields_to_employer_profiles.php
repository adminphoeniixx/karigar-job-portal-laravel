<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employer_profiles', function (Blueprint $table) {
            // Registration wizard: "You are hiring as", industry & company size.
            $table->string('hiring_as')->nullable()->after('company_name');
            $table->string('industry')->nullable()->after('hiring_as');
            $table->string('company_size')->nullable()->after('industry');
            // "Usually hiring for" trades — drives matched-worker suggestions.
            $table->json('hiring_categories')->nullable()->after('company_size');
            // Purchased contact credits (top-ups), spent on unlocks & boosts.
            $table->unsignedInteger('credit_balance')->default(0)->after('contact_quota_bonus');
        });
    }

    public function down(): void
    {
        Schema::table('employer_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'hiring_as', 'industry', 'company_size', 'hiring_categories', 'credit_balance',
            ]);
        });
    }
};
