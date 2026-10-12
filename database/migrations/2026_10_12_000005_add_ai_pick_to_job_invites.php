<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "AI shortlist from the Worker Database": the AI picks the karigars who fit
 * a job best and invites them. A karigar who accepts (applies) is shortlisted
 * and, when the employer asked for it, gets an AI screening call booked
 * (`auto_call`, at `call_at` or the next calling hour).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_invites', function (Blueprint $table) {
            $table->string('source', 10)->default('manual')->after('message'); // manual | ai
            $table->unsignedTinyInteger('ai_score')->nullable()->after('source');
            $table->text('ai_summary')->nullable()->after('ai_score');
            $table->boolean('auto_call')->default(false)->after('ai_summary');
            $table->timestamp('call_at')->nullable()->after('auto_call');
        });
    }

    public function down(): void
    {
        Schema::table('job_invites', function (Blueprint $table) {
            $table->dropColumn(['source', 'ai_score', 'ai_summary', 'auto_call', 'call_at']);
        });
    }
};
