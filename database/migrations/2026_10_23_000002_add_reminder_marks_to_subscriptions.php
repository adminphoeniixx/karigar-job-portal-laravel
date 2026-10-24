<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employers are now told before a plan renews or ends, when a renewal
 * payment fails, and when the plan has ended (App\Services\Billing\PlanReminders).
 * Each column holds the `ends_at` the notice was about, so every billing
 * cycle gets each notice once and a renewal (which moves `ends_at`) starts
 * them afresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('reminded_for')->nullable()->after('ends_at');
            $table->timestamp('payment_failed_for')->nullable()->after('reminded_for');
            $table->timestamp('lapse_notified_for')->nullable()->after('payment_failed_for');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['reminded_for', 'payment_failed_for', 'lapse_notified_for']);
        });
    }
};
