<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting an account used to take its invoices and the reviews it wrote
 * with it: both foreign keys cascaded, and so did the invoice's subscription.
 * Tax law says invoices stay, and the public /delete-account page promises
 * both stay: invoices with the buyer's details copied onto them, reviews
 * without the writer's name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['employer_id']);
            $table->dropForeign(['subscription_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('employer_id')->nullable()->change();
            // Who the invoice is made out to, copied in when the account is deleted.
            $table->json('buyer')->nullable();
            $table->foreign('employer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['reviewer_id']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('reviewer_id')->nullable()->change();
            $table->foreign('reviewer_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // The columns stay nullable: rows whose account is gone keep their null.
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['reviewer_id']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreign('reviewer_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['employer_id']);
            $table->dropForeign(['subscription_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('buyer');
            $table->foreign('employer_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
        });
    }
};
