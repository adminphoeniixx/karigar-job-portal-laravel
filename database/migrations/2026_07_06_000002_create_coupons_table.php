<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();            // stored uppercase, e.g. WELCOME20
            $table->string('description')->nullable();
            $table->string('discount_type');             // percent | flat (App\Enums\DiscountType)
            $table->decimal('discount_value', 10, 2);    // 20 = 20% or ₹20
            $table->decimal('max_discount_amount', 10, 2)->nullable(); // cap for percentage coupons
            $table->decimal('min_amount', 10, 2)->nullable();          // min plan price to qualify
            // Razorpay Offer id (offer_...) created in the Razorpay Dashboard; attached to the
            // subscription so Razorpay charges the discounted amount. Null = display-only discount.
            $table->string('razorpay_offer_id')->nullable();
            // Plans this coupon applies to. Null/empty = all plans.
            $table->json('plan_ids')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable(); // null = unlimited
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
