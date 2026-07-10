<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // GST breakup captured at purchase time (rates can change later).
            $table->decimal('subtotal_amount', 10, 2)->nullable()->after('discount_amount');
            $table->decimal('gst_percent', 5, 2)->nullable()->after('subtotal_amount');
            $table->decimal('gst_amount', 10, 2)->nullable()->after('gst_percent');
            $table->decimal('total_amount', 10, 2)->nullable()->after('gst_amount');

            // Tax invoice issued when the payment goes through.
            $table->string('invoice_number', 30)->nullable()->unique()->after('total_amount');
            $table->timestamp('invoiced_at')->nullable()->after('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'subtotal_amount', 'gst_percent', 'gst_amount', 'total_amount',
                'invoice_number', 'invoiced_at',
            ]);
        });
    }
};
