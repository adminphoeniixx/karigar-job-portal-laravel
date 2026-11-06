<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the work is done (at the employer's place, from the karigar's home,
 * or either) and how the interview is held (in person or over phone/video).
 * Every job posted before this was on-site with an on-site interview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->string('work_mode', 20)->default('on_site')->after('state');
            $table->string('interview_mode', 20)->default('on_site')->after('work_mode');
        });
    }

    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropColumn(['work_mode', 'interview_mode']);
        });
    }
};
