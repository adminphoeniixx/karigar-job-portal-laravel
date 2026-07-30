<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            // Uploaded resume/CV. The PDF itself stays on the private 'local'
            // disk (same as KYC docs); resume_text is what the AI matcher reads,
            // extracted once at upload time so scoring never re-parses the file.
            $table->string('resume_path')->nullable()->after('avatar_path');
            $table->string('resume_name')->nullable()->after('resume_path');
            $table->text('resume_text')->nullable()->after('resume_name');
            $table->timestamp('resume_uploaded_at')->nullable()->after('resume_text');
        });
    }

    public function down(): void
    {
        Schema::table('worker_profiles', function (Blueprint $table) {
            $table->dropColumn(['resume_path', 'resume_name', 'resume_text', 'resume_uploaded_at']);
        });
    }
};
