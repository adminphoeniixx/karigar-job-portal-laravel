<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrows', function (Blueprint $table) {
            $table->id();

            // One escrow guarantees payment for a single accepted application.
            $table->foreignId('job_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('amount', 10, 2);          // gross amount employer funds
            $table->decimal('commission', 10, 2)->default(0); // platform fee
            $table->decimal('payout_amount', 10, 2)->default(0); // what the worker receives
            $table->string('currency', 3)->default('INR');

            // pending | funded | release_requested | released | refunded | disputed
            $table->string('status')->default('pending')->index();

            // Razorpay money-in (Orders/Checkout)
            $table->string('razorpay_order_id')->nullable();
            $table->string('razorpay_payment_id')->nullable();
            // RazorpayX money-out (Payouts)
            $table->string('razorpay_payout_id')->nullable();

            $table->timestamp('funded_at')->nullable();
            $table->timestamp('release_requested_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('admin_note')->nullable();

            $table->timestamps();

            $table->index(['employer_id', 'status']);
            $table->index(['worker_id', 'status']);
        });

        // Immutable audit trail of every money event on an escrow.
        Schema::create('escrow_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // funded | released | refunded | fee
            $table->decimal('amount', 10, 2);
            $table->string('reference')->nullable(); // razorpay id
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrow_ledger');
        Schema::dropIfExists('escrows');
    }
};
