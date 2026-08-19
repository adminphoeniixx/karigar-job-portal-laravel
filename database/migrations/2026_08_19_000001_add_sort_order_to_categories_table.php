<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The landing page shows crafts as a photo grid, and the order there is
     * editorial — knitting and weaving lead, niche crafts trail. Alphabetical
     * ordering put "Basket / Cane Work" first, so categories carry their own
     * position. New rows default to 999 and fall to the end, sorted by name.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(999)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
