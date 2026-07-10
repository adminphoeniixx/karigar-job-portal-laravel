<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            // The account owner whose company this member works under.
            $table->foreignId('employer_id')->constrained('users')->cascadeOnDelete();
            // The member's own (employer-role) user account.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20); // manager | recruiter
            $table->timestamps();

            $table->unique('user_id'); // a user belongs to at most one team
            $table->index('employer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
