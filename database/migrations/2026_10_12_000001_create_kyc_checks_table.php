<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic verification through Decentro.
 *
 * kyc_checks logs every lookup — a PAN or GSTIN against the government
 * registry, or an Aadhaar read out of DigiLocker — and doubles as the
 * DigiLocker session the user is sent off to finish. kyc_documents keeps a
 * per-document snapshot of the checks its submission passed or failed, and
 * whether it was approved by them rather than by an admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document', 20);           // aadhaar, pan, gst
            $table->string('method', 20);             // registry, digilocker
            $table->string('reference', 64)->unique(); // ours, sent as reference_id
            $table->string('provider_txn_id')->nullable()->index();
            $table->string('number_hash')->nullable()->index();
            $table->string('status', 20)->index();    // initiated, passed, invalid, error
            $table->text('name')->nullable();         // encrypted
            $table->text('details')->nullable();      // encrypted json
            $table->string('message', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'document', 'status']);
        });

        Schema::table('kyc_documents', function (Blueprint $table) {
            // Per document: what Decentro said about it when submitted. Encrypted.
            $table->text('checks')->nullable()->after('has_missing_documents');
            $table->boolean('auto_verified')->default(false)->after('checks');
            // DigiLocker hands back only the last four digits of an Aadhaar.
            $table->string('aadhaar_last4', 4)->nullable()->after('aadhaar_hash');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->dropColumn(['checks', 'auto_verified', 'aadhaar_last4']);
        });

        Schema::dropIfExists('kyc_checks');
    }
};
