<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A cancelled plan now runs to the end of the period paid for
 * (SubscriptionStatus::runningOut()), and a plan replaced by a newer one is
 * cancelled with `ends_at` set to the switch. Plans cancelled before this
 * kept their old `ends_at` but lost access the moment they were cancelled;
 * they end at that moment here too, or they would come back next to the
 * plan that replaced them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscriptions')
            ->where('status', 'cancelled')
            ->where('ends_at', '>', now())
            ->update(['ends_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        // The old end dates are not kept; nothing reads them under the old rule.
    }
};
