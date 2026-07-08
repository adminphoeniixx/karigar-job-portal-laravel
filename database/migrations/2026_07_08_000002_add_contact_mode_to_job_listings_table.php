<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            // How workers should respond: apply in-app, call the employer, or both.
            $table->string('contact_mode', 10)->default('apply')->after('vacancies');
            $table->string('contact_phone', 20)->nullable()->after('contact_mode');
        });
    }

    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropColumn(['contact_mode', 'contact_phone']);
        });
    }
};
