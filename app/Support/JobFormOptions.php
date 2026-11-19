<?php

namespace App\Support;

use App\Jobs\ScoreApplication;
use App\Models\Category;
use App\Models\JobListing;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiCredits;
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
     * @return array<string, mixed>
     */
    public static function for(User $account): array
    {
        return [
            'category_skills' => Category::cachedSkillsMap(),
            'perks' => self::perks($account),
            // Job titles for the title dropdown, each with the category it
            // suggests; a title typed instead goes to suggest-category.
            'job_profiles' => Category::cachedJobProfiles(),
            'shifts' => ReferenceData::SHIFTS,
            'shift_defaults' => ReferenceData::SHIFT_DEFAULTS,
            'max_shift_timings' => ReferenceData::MAX_SHIFT_TIMINGS,
            'work_hours_periods' => ReferenceData::WORK_HOURS_PERIODS,
            'genders' => array_map(fn (string $gender) => ['value' => $gender, 'label' => JobListing::genderLabelFor($gender)], ReferenceData::GENDERS),
            // Where the work is done and how the interview is held, with the
            // label to show for each value.
            'work_modes' => array_map(fn (string $mode) => ['value' => $mode, 'label' => JobListing::workModeLabelFor($mode)], ReferenceData::WORK_MODES),
            'interview_modes' => array_map(fn (string $mode) => ['value' => $mode, 'label' => JobListing::interviewModeLabelFor($mode)], ReferenceData::JOB_INTERVIEW_MODES),
            'ai' => [...self::ai(), 'credits' => self::credits($account)],
            // The choices for the details added on 2026-11-19 (job type,
            // education, English, reach, fee purpose, documents, days,
            // urgency), each {value, label}, plus the limits the form checks.
            'details' => [
                ...JobDetailLabels::options(),
                'spoken_languages' => ReferenceData::SPOKEN_LANGUAGES,
                'default_work_days' => ReferenceData::DEFAULT_WORK_DAYS,
                'age_min' => ReferenceData::JOB_AGE_MIN,
                'age_max' => ReferenceData::JOB_AGE_MAX,
                'nearby_km' => ReferenceData::NEARBY_KM,
            ],
            // Per category, the requirements most of its jobs ask for (the
            // empty key: the usual ones). Filled in once a category is picked.
            'requirement_defaults' => JobRequirementDefaults::all(),
        ];
    }

    /**
     * The AI call credits the job form shows beside the AI call switch. With
     * `can_enable` false the switch needs a recharge first.
     *
     * @return array{enabled: bool, balance: int, min_for_job: int, can_enable: bool}
     */
    public static function credits(User $account): array
    {
        $wallet = AiCredits::for($account);
        $wallet->grantWelcome();

        return [
            // Off: AI calls are free and need no credits.
            'enabled' => AiCredits::enabled(),
            'balance' => $wallet->balance(),
            'min_for_job' => AiCredits::jobMinimum(),
            'can_enable' => $wallet->meetsJobMinimum(),
        ];
    }
}
