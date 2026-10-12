<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Worker Database: an employer can turn a karigar down without unlocking
 * their number (worker_rejections). Karigar cards show how far away the
 * karigar is and in which area, so profiles gain the area and a note of
 * where their map pin came from: their phone or the area they picked (a
 * real distance), or only their city's centre (an approximate one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_rejections', function (Blueprint $table) {
            $table->id();
            // The employer account (owner); a team member rejects for it.
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employer_id', 'worker_id']);
        });

        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->string('area', 120)->nullable()->after('city');
            // gps | area | city: whether the pin is the karigar's own or a city centre.
            $table->string('location_source', 10)->nullable()->after('longitude');
        });

        // Pins placed so far: `karigars:locate` put everyone without one on
        // their city's centre, so a pin other karigars share is a city
        // centre. The few left are the karigars' own.
        DB::table('worker_profiles')
            ->whereNotNull('latitude')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('worker_profiles as other')
                ->whereColumn('other.latitude', 'worker_profiles.latitude')
                ->whereColumn('other.longitude', 'worker_profiles.longitude')
                ->whereColumn('other.id', '!=', 'worker_profiles.id'))
            ->update(['location_source' => 'city']);

        DB::table('worker_profiles')
            ->whereNotNull('latitude')
            ->whereNull('location_source')
            ->update(['location_source' => 'gps']);
    }

    public function down(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->dropColumn(['area', 'location_source']);
        });

        Schema::dropIfExists('worker_rejections');
    }
};
