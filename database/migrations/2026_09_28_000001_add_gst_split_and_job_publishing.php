<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // How the GST on this invoice splits. Same state as the seller's
            // GSTIN → CGST + SGST, half each; any other state → all IGST.
            $table->decimal('cgst_amount', 10, 2)->nullable()->after('gst_amount');
            $table->decimal('sgst_amount', 10, 2)->nullable()->after('cgst_amount');
            $table->decimal('igst_amount', 10, 2)->nullable()->after('sgst_amount');
            // The buyer's state, as "Haryana (06)", which decided the split.
            $table->string('place_of_supply', 60)->nullable()->after('igst_amount');
            // Snapshots, so an old invoice keeps reading as it was issued.
            $table->string('seller_gstin', 15)->nullable()->after('place_of_supply');
            $table->string('sac_code', 10)->nullable()->after('seller_gstin');
        });

        Schema::table('plans', function (Blueprint $table) {
            // The amount (GST included) the linked Razorpay plan charges. A
            // Razorpay plan cannot be edited, so when the price or the GST rate
            // changes a new one is created and this tells us when.
            $table->decimal('razorpay_amount', 10, 2)->nullable()->after('razorpay_plan_id');
        });

        Schema::table('job_listings', function (Blueprint $table) {
            // When the job first went live. Drafts have none, and it is what the
            // plan's job-post limit counts, per billing cycle.
            $table->timestamp('published_at')->nullable()->after('status')->index();
        });

        DB::table('job_listings')
            ->where('status', '!=', 'draft')
            ->update(['published_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['cgst_amount', 'sgst_amount', 'igst_amount', 'place_of_supply', 'seller_gstin', 'sac_code']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('razorpay_amount');
        });

        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropIndex(['published_at']);
            $table->dropColumn('published_at');
        });
    }
};
