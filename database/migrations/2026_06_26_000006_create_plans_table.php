<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price', 10, 2);          // rupees
            $table->string('currency', 3)->default('INR');
            $table->string('interval')->default('monthly'); // monthly | yearly
            $table->string('razorpay_plan_id')->nullable();
            $table->jsonb('features')->nullable(); // { job_post_limit, contact_unlock_limit, featured }
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
