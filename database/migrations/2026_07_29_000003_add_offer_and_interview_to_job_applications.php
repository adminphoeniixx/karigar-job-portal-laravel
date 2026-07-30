<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            // Hire sheet: offered wage / start date / message to the worker.
            $table->decimal('offered_wage', 10, 2)->nullable()->after('expected_wage');
            $table->date('start_date')->nullable()->after('offered_wage');
            $table->text('offer_message')->nullable()->after('start_date');

            // Interview stage between "shortlisted" and "hired".
            $table->timestamp('interview_at')->nullable()->after('shortlisted_at');
            $table->string('interview_mode')->nullable()->after('interview_at');
            $table->text('interview_note')->nullable()->after('interview_mode');
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropColumn([
                'offered_wage', 'start_date', 'offer_message',
                'interview_at', 'interview_mode', 'interview_note',
            ]);
        });
    }
};
