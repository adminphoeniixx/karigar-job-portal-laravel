<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI call credits: employers recharge a prepaid balance, and every AI
 * screening call takes credits for the minutes it ran.
 *
 * - ai_credit_packs: what the admin sells (₹ price → credits, plus bonus).
 * - ai_credit_purchases: one Razorpay order per recharge, with its GST.
 * - ai_credit_entries: the ledger. Every change to a balance is one row, and
 *   users.ai_credits is the running total those rows add up to.
 *
 * Separate from the contact-unlock credits removed on 2026-10-06; the old
 * users.credit_balance column is history and is not used here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_packs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            // Before GST.
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('credits');
            $table->unsignedInteger('bonus_credits')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_popular')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ai_credit_pack_id')->nullable()->constrained()->nullOnDelete();
            // Snapshots: the pack can change after the purchase.
            $table->string('pack_name', 80);
            $table->unsignedInteger('credits');
            $table->unsignedInteger('bonus_credits')->default(0);
            $table->string('status', 12)->default('created'); // created | paid
            $table->string('razorpay_order_id')->unique();
            $table->string('razorpay_payment_id')->nullable()->unique();
            $table->decimal('subtotal_amount', 10, 2);
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->decimal('gst_amount', 10, 2)->default(0);
            $table->decimal('cgst_amount', 10, 2)->nullable();
            $table->decimal('sgst_amount', 10, 2)->nullable();
            $table->decimal('igst_amount', 10, 2)->nullable();
            $table->decimal('total_amount', 10, 2);
            $table->string('place_of_supply')->nullable();
            $table->string('seller_gstin')->nullable();
            $table->string('sac_code')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            // recharge | bonus | welcome | call | admin_credit | admin_debit | refund
            $table->string('type', 16);
            // Signed: + adds to the balance, − takes from it.
            $table->integer('credits');
            $table->integer('balance_after');
            $table->string('description', 255);
            $table->foreignId('ai_credit_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('screening_call_id')->nullable()->constrained()->nullOnDelete();
            // Call minutes charged, for the ledger line.
            $table->unsignedInteger('seconds')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employer_id', 'id']);
            // One charge per call, one recharge (and one bonus) per purchase:
            // a callback and a webhook reporting the same thing add it once.
            $table->unique(['screening_call_id', 'type']);
            $table->unique(['ai_credit_purchase_id', 'type']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->integer('ai_credits')->default(0);
            $table->timestamp('ai_welcome_credited_at')->nullable();
            $table->timestamp('ai_low_credit_alerted_at')->nullable();
        });

        Schema::table('screening_calls', function (Blueprint $table) {
            $table->unsignedInteger('credits_charged')->nullable();
        });

        // A recharge invoice belongs to a purchase, not a subscription.
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->change();
            $table->foreignId('ai_credit_purchase_id')->nullable()->unique()->constrained()->nullOnDelete();
        });

        // Starter packs, at roughly ₹10–12 a minute with 1 credit a minute.
        // The admin edits or hides them in Admin → AI Credits.
        $now = now();
        DB::table('ai_credit_packs')->insert([
            ['name' => 'Starter', 'price' => 499, 'credits' => 40, 'bonus_credits' => 0, 'is_active' => true, 'is_popular' => false, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Growth', 'price' => 999, 'credits' => 90, 'bonus_credits' => 10, 'is_active' => true, 'is_popular' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Pro', 'price' => 2499, 'credits' => 230, 'bonus_credits' => 40, 'is_active' => true, 'is_popular' => false, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_credit_purchase_id');
        });

        Schema::table('screening_calls', function (Blueprint $table) {
            $table->dropColumn('credits_charged');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ai_credits', 'ai_welcome_credited_at', 'ai_low_credit_alerted_at']);
        });

        Schema::dropIfExists('ai_credit_entries');
        Schema::dropIfExists('ai_credit_purchases');
        Schema::dropIfExists('ai_credit_packs');
    }
};
