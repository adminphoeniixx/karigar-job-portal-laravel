<?php

use App\Support\WageConversion;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Wages are monthly from now on. Daily wages become x26 (working days),
     * hourly x208 (8 hours a day), on jobs, on karigar profiles, and on the
     * applications and offers made against those jobs.
     */
    public function up(): void
    {
        WageConversion::run();
    }

    public function down(): void
    {
        // The daily and hourly figures are gone; there is nothing to go back to.
    }
};
