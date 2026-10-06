<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the karigar wants to see jobs around, when it is not where they are
 * standing: a town they are moving to, a site area. Empty means "my current
 * location". Kept apart from latitude/longitude, which is where they live and
 * what employers see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->string('feed_location_label', 150)->nullable()->after('travel_radius_km');
            $table->decimal('feed_latitude', 10, 7)->nullable()->after('feed_location_label');
            $table->decimal('feed_longitude', 10, 7)->nullable()->after('feed_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->dropColumn(['feed_location_label', 'feed_latitude', 'feed_longitude']);
        });
    }
};
