<?php

namespace App\Support;

/**
 * Static reference lists the mobile app needs to populate dropdowns / chips
 * during registration and job filtering. Mirrors the frontend TS data files
 * (resources/js/data/*) so web and app stay in sync.
 */
class ReferenceData
{
    /**
     * Languages a worker may speak — used for job matching (not the app UI locale).
     *
     * @var list<string>
     */
    public const SPOKEN_LANGUAGES = [
        'Hindi', 'English', 'Tamil', 'Telugu', 'Kannada', 'Malayalam',
        'Marathi', 'Bengali', 'Gujarati', 'Punjabi', 'Odia', 'Urdu',
    ];

    /**
     * Highest-education options, low → high.
     *
     * @var list<string>
     */
    public const EDUCATION_LEVELS = [
        'Below 10th', '10th Pass', '12th Pass', 'ITI / Diploma', 'Graduate', 'Post Graduate',
    ];

    /**
     * Wage periods on offer. Only monthly now: a daily or hourly figure from
     * an older app build is converted on the way in (App\Support\Wage).
     *
     * @var list<string>
     */
    public const WAGE_TYPES = [Wage::MONTHLY];

    /**
     * Work-shift options for a job post (must match JobListingRequest shift rule).
     *
     * @var list<string>
     */
    public const SHIFTS = ['day', 'night', 'rotational', 'flexible'];

    /**
     * Perks suggested on the job form. Only suggestions: an employer can add
     * any perk of its own, and its past ones are offered back to it.
     *
     * @var list<string>
     */
    public const PERKS = [
        'ESI', 'PF', 'Food', 'Accommodation', 'Travel allowance', 'Bonus', 'Overtime pay',
        'Weekly off', 'Paid leave', 'Medical insurance', 'Uniform', 'Tools provided',
    ];

    /**
     * How a worker may reach the employer (must match JobListingRequest contact_mode rule).
     *
     * @var list<string>
     */
    public const CONTACT_MODES = ['apply', 'call', 'both'];

    /**
     * Industry options for the employer registration/profile dropdown.
     *
     * @var list<string>
     */
    public const INDUSTRIES = [
        'Construction & Real Estate', 'Interiors & Furnishing', 'Facility & Maintenance',
        'Manufacturing', 'Home Services', 'Hospitality', 'Logistics & Transport', 'Other',
    ];

    /**
     * Company-size buckets for the employer registration dropdown.
     *
     * @var list<string>
     */
    public const COMPANY_SIZES = ['1–10', '11–50', '51–200', '200+'];

    /**
     * "You are hiring as" options on the employer registration wizard.
     *
     * @var list<string>
     */
    public const HIRING_AS = ['business', 'contractor', 'individual'];

    /**
     * How an interview can be held (Schedule interview sheet).
     *
     * @var list<string>
     */
    public const INTERVIEW_MODES = ['site', 'phone', 'video'];

    /**
     * Sort options for the employer's Find Workers screen.
     *
     * @var list<string>
     */
    public const WORKER_SORTS = ['best_match', 'nearest', 'rating', 'experience', 'wage_low'];

    /**
     * App UI languages (mirrors the LANGS list in the frontend switcher).
     *
     * @var list<array{code: string, native: string, english: string}>
     */
    public const APP_LANGUAGES = [
        ['code' => 'hinglish', 'native' => 'Hindi + English', 'english' => 'Hinglish'],
        ['code' => 'en', 'native' => 'English', 'english' => 'English'],
        ['code' => 'hi', 'native' => 'हिन्दी', 'english' => 'Hindi'],
        ['code' => 'ta', 'native' => 'தமிழ்', 'english' => 'Tamil'],
        ['code' => 'te', 'native' => 'తెలుగు', 'english' => 'Telugu'],
        ['code' => 'bn', 'native' => 'বাংলা', 'english' => 'Bengali'],
        ['code' => 'mr', 'native' => 'मराठी', 'english' => 'Marathi'],
    ];

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return IndianCities::states();
    }

    /**
     * @return list<string>
     */
    public static function citiesFor(?string $state): array
    {
        return IndianCities::citiesFor($state);
    }
}
