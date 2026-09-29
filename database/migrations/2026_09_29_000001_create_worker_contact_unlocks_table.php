<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A karigar the employer unlocked straight from the Worker Database, with
     * no application in between. It spends from the same contact-unlock pool
     * as applicants, and one row per pair means a karigar is paid for once.
     */
    public function up(): void
    {
        Schema::create('worker_contact_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employer_id', 'worker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_contact_unlocks');
    }
};
