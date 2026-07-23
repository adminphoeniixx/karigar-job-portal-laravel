<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * History log of manual push broadcasts sent by admins, so the admin
     * panel can show what was sent, to whom, and how many devices it reached.
     */
    public function up(): void
    {
        Schema::create('push_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('body');
            // Audience type: all | worker | city | category.
            $table->string('audience', 20)->default('all');
            // Audience selector payload, e.g. {"worker_id":5} / {"city":"Jaipur"} / {"category":"Plumbing"}.
            $table->json('target')->nullable();
            // Optional deep-link the app opens when the notification is tapped.
            $table->string('url')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_campaigns');
    }
};
