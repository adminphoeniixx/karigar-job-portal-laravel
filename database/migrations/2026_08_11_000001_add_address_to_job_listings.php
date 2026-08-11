<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            // Street address / landmark. City and state alone only ever put the
            // map pin on the city centre; this is what the worker actually
            // travels to, and what the geocoder uses to place the pin.
            $table->string('address', 255)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
