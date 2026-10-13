<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            // The AI resume builder: what the karigar typed into its form and
            // the resume they last saved from it, so the form opens filled in
            // next time. resume_source says whether the file on record was
            // uploaded or built here.
            $table->jsonb('resume_builder')->nullable()->after('resume_uploaded_at');
            $table->string('resume_source', 10)->nullable()->after('resume_builder');
        });
    }

    public function down(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->dropColumn(['resume_builder', 'resume_source']);
        });
    }
};
