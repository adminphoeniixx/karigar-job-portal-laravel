<?php

namespace App\Support;

use App\Models\Category;
use App\Models\JobListing;
use App\Models\User;

/**
 * What the job form offers an employer, on the web and in the app: each
 * category's skills, and the perks to pick from — the usual ones, then the
 * employer's own from its earlier jobs, so a perk it once typed comes back.
 */
final class JobFormOptions
{
    /**
     * @return list<string>
     */
    public static function perks(User $account): array
    {
        $own = JobListing::where('employer_id', $account->employerAccount()->id)
            ->whereNotNull('perks')
            ->pluck('perks')
            ->flatten()
            ->filter(fn ($perk) => is_string($perk) && trim($perk) !== '')
            ->map(fn (string $perk) => trim($perk));

        return collect(ReferenceData::PERKS)
            ->merge($own)
            ->unique(fn (string $perk) => mb_strtolower($perk))
            ->values()
            ->all();
    }

    /**
     * @return array{category_skills: array<string, list<string>>, perks: list<string>, shifts: list<string>}
     */
    public static function for(User $account): array
    {
        return [
            'category_skills' => Category::cachedSkillsMap(),
            'perks' => self::perks($account),
            'shifts' => ReferenceData::SHIFTS,
        ];
    }
}
