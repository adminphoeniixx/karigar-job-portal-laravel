<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One tax invoice per payment. A subscription used to carry a single invoice
 * number, so the monthly renewals Razorpay charged went out with no invoice
 * at all. Each row snapshots what was charged on that payment.
 *
 * Numbers run in one series per financial year with no gaps (GST rule 46):
 * invoice_counters holds the last number handed out in each year. Invoices
 * issued before this table existed keep their old numbers and have no
 * financial_year / sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            // 1 = the first payment, 2 = the first renewal, and so on.
            $table->unsignedInteger('cycle');
            $table->string('number')->unique();
            $table->string('financial_year', 7)->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->timestamp('issued_at');
            $table->string('razorpay_payment_id')->nullable()->unique();
            $table->string('plan_name');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->decimal('subtotal_amount', 10, 2);
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->decimal('gst_amount', 10, 2)->default(0);
            // Null on old invoices that printed GST as a single line.
            $table->decimal('cgst_amount', 10, 2)->nullable();
            $table->decimal('sgst_amount', 10, 2)->nullable();
            $table->decimal('igst_amount', 10, 2)->nullable();
            $table->decimal('total_amount', 10, 2);
            $table->string('place_of_supply')->nullable();
            $table->string('seller_gstin')->nullable();
            $table->string('sac_code')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'cycle']);
            $table->unique(['financial_year', 'sequence']);
            $table->index(['employer_id', 'issued_at']);
        });

        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->string('financial_year', 7)->primary();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_counters');
        Schema::dropIfExists('invoices');
    }
};
