<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            // Where escrow payouts are sent. UPI is the default rail via RazorpayX.
            $table->string('payout_upi')->nullable()->after('available');
            // Cached RazorpayX fund account id so we don't recreate it per payout.
            $table->string('razorpayx_fund_account_id')->nullable()->after('payout_upi');
        });
    }

    public function down(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->dropColumn(['payout_upi', 'razorpayx_fund_account_id']);
        });
    }
};
