<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores per-device FCM registration tokens so we can deliver push
     * notifications to a user's phones/tablets. One user may have several.
     */
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The FCM registration token reported by the mobile app.
            $table->text('token');
            // android | ios | web (informational, nullable for older clients).
            $table->string('platform', 20)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // A registration token is globally unique to a single device.
            $table->unique('token');
            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
