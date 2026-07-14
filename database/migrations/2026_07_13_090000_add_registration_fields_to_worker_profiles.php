<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            // Collected during the guided mobile registration (WorkIndia-style),
            // and used to match workers to jobs.
            $table->string('gender')->nullable()->after('phone'); // male | female | other
            $table->string('education')->nullable()->after('experience_years'); // e.g. "12th Pass"
            $table->jsonb('spoken_languages')->nullable()->after('education'); // ["Hindi","Tamil"]
            $table->unsignedSmallInteger('travel_radius_km')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->dropColumn(['gender', 'education', 'spoken_languages', 'travel_radius_km']);
        });
    }
};
