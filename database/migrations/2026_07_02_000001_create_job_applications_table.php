<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('users')->cascadeOnDelete();
            $table->text('cover_note')->nullable();
            $table->decimal('expected_wage', 10, 2)->nullable();
            $table->string('status')->default('pending'); // pending | accepted | rejected | withdrawn
            $table->boolean('contact_unlocked')->default(false);
            $table->timestamps();

            $table->unique(['job_listing_id', 'worker_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_applications');
    }
};
