<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            // Whether the worker must pay anything (deposit/fee) to take this job.
            $table->boolean('requires_worker_fee')->default(false)->after('perks');
            $table->decimal('worker_fee_amount', 10, 2)->nullable()->after('requires_worker_fee');
        });
    }

    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropColumn(['requires_worker_fee', 'worker_fee_amount']);
        });
    }
};
