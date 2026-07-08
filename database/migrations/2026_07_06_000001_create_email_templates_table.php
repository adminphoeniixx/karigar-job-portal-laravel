<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            // Stable system key the code looks up (e.g. "application_accepted").
            $table->string('key')->unique();
            // Human-friendly name + note shown in the admin editor.
            $table->string('name');
            $table->string('description')->nullable();
            // Editable content. Subject + HTML body may contain {{ placeholder }} tokens.
            $table->string('subject');
            $table->text('body_html');
            // The placeholders available for this template (for the admin hint panel).
            $table->json('placeholders')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
