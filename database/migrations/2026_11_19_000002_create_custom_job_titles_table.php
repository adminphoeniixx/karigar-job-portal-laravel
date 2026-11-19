<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Job titles employers type that no active category lists. Admin → Categories
 * shows them, so the good ones can join a category's job titles (and the job
 * form's title list) and the rest can be hidden for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_job_titles', function (Blueprint $table) {
            $table->id();
            // As the first employer typed it; `normalized` keeps one row per title.
            $table->string('title');
            $table->string('normalized')->unique();
            // Set when an admin hides it, so it never comes back to the list.
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
        });

        // Titles typed in the last 90 days, so the list does not start empty.
        // Older jobs are mostly seeded demo data.
        $listed = DB::table('categories')->where('is_active', true)->pluck('job_titles')
            ->flatMap(fn ($titles) => json_decode((string) $titles, true) ?: [])
            ->map(fn ($title) => mb_strtolower(trim((string) $title)))
            ->flip();

        DB::table('job_listings')
            ->where('created_at', '>=', now()->subDays(90))
            ->orderBy('created_at')
            ->get(['title', 'created_at'])
            ->each(function (object $job) use ($listed) {
                $normalized = mb_strtolower(trim((string) $job->title));

                if ($normalized === '' || $listed->has($normalized)) {
                    return;
                }

                DB::table('custom_job_titles')->insertOrIgnore([
                    'title' => trim((string) $job->title),
                    'normalized' => $normalized,
                    'created_at' => $job->created_at,
                    'updated_at' => $job->created_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_job_titles');
    }
};
