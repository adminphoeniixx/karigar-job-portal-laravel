<?php

namespace App\Support;

use App\Models\Category;

/**
 * Which category a job title belongs to, and the skills to suggest for it.
 * The job form calls it when the employer picks a job profile or types a
 * title of their own ("Need blouse tailor urgently" → Tailoring, with
 * "Blouse stitching" first).
 *
 * Plain word matching against the admin's lists — each active category's job
 * titles, its name and its skills — so it is instant and costs nothing. Words
 * are compared by stem, so "weaver", "weaving" and "weavers" all meet.
 */
class JobProfileMatcher
{
    /** Below this a match is a guess not worth showing. */
    private const MIN_SCORE = 8;

    /** Words that say nothing about the trade. */
    private const STOP_WORDS = [
        'and', 'for', 'the', 'with', 'need', 'needed', 'urgent', 'urgently', 'wanted', 'required', 'hiring',
        'job', 'jobs', 'work', 'worker', 'workers', 'staff', 'karigar', 'karigars', 'artisan', 'artisans',
        'experience', 'experienced', 'skilled', 'expert', 'senior', 'junior', 'fresher', 'freshers', 'full', 'part', 'time',
    ];

    /**
     * @return array{category: string, job_profile: ?string, skills: list<string>}|null
     */
    public static function suggest(string $title): ?array
    {
        $typed = self::normalise($title);

        if ($typed === '') {
            return null;
        }

        $typedStems = self::stems($typed);
        $skillsMap = Category::cachedSkillsMap();
        $best = null;
        $runnerUp = 0;

        foreach (self::categories() as $category => $titles) {
            [$score, $profile] = self::score($typed, $typedStems, $category, $titles, $skillsMap[$category] ?? []);

            if ($best === null || $score > $best['score']) {
                $runnerUp = $best['score'] ?? 0;
                $best = ['score' => $score, 'category' => $category, 'job_profile' => $profile];
            } elseif ($score > $runnerUp) {
                $runnerUp = $score;
            }
        }

        // Nothing close, or two crafts fit equally well ("machine operator"):
        // better no suggestion than a wrong one.
        if ($best === null || $best['score'] < self::MIN_SCORE || $best['score'] === $runnerUp) {
            return null;
        }

        return [
            'category' => $best['category'],
            'job_profile' => $best['job_profile'],
            'skills' => self::rankSkills($skillsMap[$best['category']] ?? [], $typedStems),
        ];
    }

    /**
     * @param  list<string>  $typedStems
     * @param  list<string>  $titles
     * @param  list<string>  $skills
     * @return array{0: int, 1: ?string} the score and the job title that matched best
     */
    private static function score(string $typed, array $typedStems, string $category, array $titles, array $skills): array
    {
        $score = 0;
        $profile = null;

        foreach ($titles as $title) {
            $candidate = self::normalise($title);
            $stems = self::stems($candidate);

            $points = match (true) {
                $candidate === $typed => 100,
                // "need a blouse tailor" holds "blouse tailor": longer = more specific.
                str_contains(" {$typed} ", " {$candidate} ") => 50 + strlen($candidate),
                default => 10 * count(array_intersect($typedStems, $stems)),
            };

            if ($points > $score) {
                $score = $points;
                $profile = $points >= 50 ? $title : null;
            }
        }

        // The category's own name and skills count for less than a job title.
        $score += 8 * count(array_intersect($typedStems, self::stems(self::normalise($category))));
        $score += 4 * min(2, count(array_intersect($typedStems, self::stems(self::normalise(implode(' ', $skills))))));

        return [$score, $profile];
    }

    /**
     * The category's skills, those sharing a word with the title first.
     *
     * @param  list<string>  $skills
     * @param  list<string>  $typedStems
     * @return list<string>
     */
    private static function rankSkills(array $skills, array $typedStems): array
    {
        return collect($skills)
            ->sortByDesc(fn (string $skill) => count(array_intersect($typedStems, self::stems(self::normalise($skill)))))
            ->values()
            ->all();
    }

    /**
     * Active categories with their job titles, in category order.
     *
     * @return array<string, list<string>>
     */
    private static function categories(): array
    {
        $titles = collect(Category::cachedJobProfiles())->groupBy('category')->map->pluck('title');

        return collect(Category::cachedActiveNames())
            ->mapWithKeys(fn (string $name) => [$name => $titles->get($name)?->all() ?? []])
            ->all();
    }

    private static function normalise(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text)));
    }

    /**
     * Distinct word stems of three letters or more: "weavers" → "weav".
     *
     * @return list<string>
     */
    private static function stems(string $normalised): array
    {
        return collect(explode(' ', $normalised))
            ->filter(fn (string $word) => strlen($word) >= 3 && ! in_array($word, self::STOP_WORDS, true))
            ->map(function (string $word): string {
                $stem = (string) preg_replace('/(ings|ing|ers|er|s|y)$/', '', $word);

                return strlen($stem) >= 3 ? $stem : $word;
            })
            ->unique()
            ->values()
            ->all();
    }
}
