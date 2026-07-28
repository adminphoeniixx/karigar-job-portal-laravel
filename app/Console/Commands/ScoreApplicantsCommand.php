<?php

namespace App\Console\Commands;

use App\Jobs\ScoreApplication;
use App\Models\JobApplication;
use Illuminate\Console\Command;

/**
 * Queues AI scoring for applicants. Run ad-hoc to backfill existing data, or on
 * a schedule to catch anything the apply-time dispatch missed.
 *
 *   php artisan ai:score-applicants          # only unscored
 *   php artisan ai:score-applicants --force   # re-score everyone
 */
class ScoreApplicantsCommand extends Command
{
    protected $signature = 'ai:score-applicants {--force : Re-score applicants that already have a score}';

    protected $description = 'Queue AI match-scoring for job applicants';

    public function handle(): int
    {
        $ids = JobApplication::query()
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('ai_scored_at'))
            ->pluck('id');

        foreach ($ids as $id) {
            ScoreApplication::dispatch((int) $id);
        }

        $this->info("Queued {$ids->count()} applicant(s) for AI scoring.");

        return self::SUCCESS;
    }
}
