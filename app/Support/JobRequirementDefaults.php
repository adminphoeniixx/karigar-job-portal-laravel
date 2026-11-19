<?php

namespace App\Support;

use App\Models\JobListing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What the job form fills in for "who you need" once a category is picked,
 * as WorkIndia does ("Auto-filled, based on top-performing jobs for this
 * role"): for each requirement, the answer most jobs in that category gave
 * over the last year. A category with too few answers gets what craft work
 * usually needs instead. The employer can change any of it.
 */
final class JobRequirementDefaults
{
    /** The requirements filled in, and what each starts at when nothing better is known. */
    public const FALLBACK = [
        'experience_level' => 'any',
        'education' => 'Below 10th',
        'english' => 'none',
        'gender' => 'both',
        'reach' => 'city',
    ];

    /** Fewer answers than this in a category, and the usual value stands. */
    public const MIN_ANSWERS = 3;

    private const CACHE_KEY = 'jobs.requirement_defaults';

    /**
     * Each category's defaults, how many of its jobs they come from, and
     * which of them those jobs answered (the rest are the usual values).
     * The empty key holds the usual values, for a job with no category.
     *
     * @return array<string, array{values: array<string, string>, jobs: int, from_jobs: list<string>}>
     */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(6), fn () => self::compute());
    }

    /**
     * @return array{values: array<string, string>, jobs: int, from_jobs: list<string>}
     */
    public static function for(?string $category): array
    {
        $all = self::all();

        return $all[(string) $category] ?? $all[''];
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array{values: array<string, string>, jobs: int, from_jobs: list<string>}>
     */
    private static function compute(): array
    {
        $defaults = ['' => ['values' => self::FALLBACK, 'jobs' => 0, 'from_jobs' => []]];

        JobListing::query()
            ->whereNotNull('published_at')
            ->where('published_at', '>=', now()->subYear())
            ->whereNotNull('category')
            ->latest('published_at')
            ->get(['category', 'experience_min', 'experience_max', 'education', 'english', 'gender', 'reach'])
            ->groupBy('category')
            ->each(function (Collection $jobs, string $category) use (&$defaults) {
                $values = self::FALLBACK;
                $fromJobs = [];

                foreach (array_keys(self::FALLBACK) as $field) {
                    $answers = $jobs
                        ->map(fn (JobListing $job) => $field === 'experience_level' ? $job->experienceLevel() : $job->{$field})
                        ->filter(fn ($value) => $value !== null && $value !== '');

                    if ($answers->count() >= self::MIN_ANSWERS) {
                        // The most given answer; a tie goes to the one the newest jobs gave.
                        $values[$field] = (string) $answers->countBy()->sortDesc()->keys()->first();
                        $fromJobs[] = $field;
                    }
                }

                $defaults[$category] = ['values' => $values, 'jobs' => $jobs->count(), 'from_jobs' => $fromJobs];
            });

        return $defaults;
    }
}
