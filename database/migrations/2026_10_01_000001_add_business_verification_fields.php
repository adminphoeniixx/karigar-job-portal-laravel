<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verification by business type, and the "I don't have this document" route.
 *
 * Employer KYC used to park the GST certificate in aadhaar_doc_path. It gets
 * its own column now; existing rows are copied (not moved) so the admin page
 * still deployed keeps finding the certificate where it looks for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table) {
            // An employer's business type when it submitted; decides the documents asked for.
            $table->string('business_type')->nullable()->after('user_id');
            $table->string('gst_doc_path')->nullable()->after('aadhaar_doc_path');
            // Per document: the alternate ID offered instead, its proof and the reason. Encrypted.
            $table->text('missing_documents')->nullable()->after('gst_doc_path');
            $table->boolean('has_missing_documents')->default(false)->index()->after('missing_documents');
        });

        Schema::table('employer_profiles', function (Blueprint $table) {
            $table->string('business_type')->nullable()->after('company_name');
            $table->string('legal_name')->nullable()->after('business_type');
            $table->text('registered_address')->nullable()->after('legal_name');
        });

        DB::table('kyc_documents')
            ->whereIn('user_id', DB::table('users')->select('id')->where('role', 'employer'))
            ->whereNull('gst_doc_path')
            ->update(['gst_doc_path' => DB::raw('aadhaar_doc_path')]);
    }

    public function down(): void
    {
        Schema::table('employer_profiles', function (Blueprint $table) {
            $table->dropColumn(['business_type', 'legal_name', 'registered_address']);
        });

        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->dropIndex(['has_missing_documents']);
            $table->dropColumn(['business_type', 'gst_doc_path', 'missing_documents', 'has_missing_documents']);
        });
    }
};
