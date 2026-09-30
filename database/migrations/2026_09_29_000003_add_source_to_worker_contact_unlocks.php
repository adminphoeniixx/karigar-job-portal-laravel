<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a karigar was unlocked from: the Worker Database, or one of the
     * employer's own applicants. The contacts page lists the two apart. The
     * rows backfilled from applications are the only ones without an
     * unlocked_by, so that is how they are told apart here.
     */
    public function up(): void
    {
        Schema::table('worker_contact_unlocks', function (Blueprint $table) {
            $table->string('source', 16)->default('directory')->after('worker_id');
            $table->index(['employer_id', 'source', 'created_at']);
        });

        DB::table('worker_contact_unlocks')->whereNull('unlocked_by')->update(['source' => 'application']);
    }

    public function down(): void
    {
        Schema::table('worker_contact_unlocks', function (Blueprint $table) {
            $table->dropIndex(['employer_id', 'source', 'created_at']);
            $table->dropColumn('source');
        });
    }
};
