<?php

namespace App\Support;

use App\Jobs\ScoreApplication;
use App\Models\Category;
use App\Models\JobListing;
use App\Models\Setting;
use App\Models\User;
use App\Services\Screening\ScreeningService;

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
     * Which of the job's AI switches the admin has turned on platform-wide. A
     * switch the admin has off is shown disabled: the employer's choice is
     * kept but does nothing until the admin turns the feature on. The call
     * only ever follows an auto-shortlist, so it needs both.
     *
     * @return array{shortlist_available: bool, call_available: bool}
     */
    public static function ai(): array
    {
        $shortlist = Setting::bool(ScoreApplication::ENABLED_KEY, false);

        return [
            'shortlist_available' => $shortlist,
            'call_available' => $shortlist && Setting::bool(ScreeningService::ENABLED_KEY, false),
        ];
    }

    /**
     * @return array{category_skills: array<string, list<string>>, perks: list<string>, shifts: list<string>, ai: array{shortlist_available: bool, call_available: bool}}
     */
    public static function for(User $account): array
    {
        return [
            'category_skills' => Category::cachedSkillsMap(),
            'perks' => self::perks($account),
            'shifts' => ReferenceData::SHIFTS,
            'ai' => self::ai(),
        ];
    }
}
