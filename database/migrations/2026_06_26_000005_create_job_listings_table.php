<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named job_listings (not "jobs") to avoid clashing with the queue jobs table.
        Schema::create('job_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('category')->nullable()->index();
            $table->jsonb('skills')->nullable();
            $table->decimal('wage_min', 10, 2)->nullable();
            $table->decimal('wage_max', 10, 2)->nullable();
            $table->string('wage_type')->nullable(); // hourly | daily | monthly
            $table->string('city')->nullable()->index();
            $table->string('state')->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('vacancies')->default(1);
            $table->string('status')->default('draft')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'state', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_listings');
    }
};
