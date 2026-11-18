<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\KycRequirements;
use App\Support\ReferenceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Static/reference data the mobile app needs to build its dropdowns, chips
 * and filters during registration and job browsing.
 */
class ReferenceController extends Controller
{
    /**
     * Everything in one call so the app can cache it on first launch.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'states' => ReferenceData::states(),
            // What a karigar picks at sign-up is what jobs are posted under,
            // so the two match: all active craft categories.
            'skills' => $this->categories(),
            'spoken_languages' => ReferenceData::SPOKEN_LANGUAGES,
            'education_levels' => ReferenceData::EDUCATION_LEVELS,
            'wage_types' => ReferenceData::WAGE_TYPES,
            'app_languages' => ReferenceData::APP_LANGUAGES,
            'job_categories' => $this->categories(),
            // Skills to suggest once a category is picked on the job form.
            'category_skills' => Category::cachedSkillsMap(),
            // Job titles ("job profiles") for the job form's title dropdown,
            // each with the category it belongs to.
            'job_profiles' => Category::cachedJobProfiles(),
            // Employer-app dropdowns.
            'shifts' => ReferenceData::SHIFTS,
            // Hours to pre-fill for a day / night shift, how many shifts a
            // rotational job may list, and a flexible job's hour periods.
            'shift_defaults' => ReferenceData::SHIFT_DEFAULTS,
            'max_shift_timings' => ReferenceData::MAX_SHIFT_TIMINGS,
            'work_hours_periods' => ReferenceData::WORK_HOURS_PERIODS,
            'genders' => ReferenceData::GENDERS,
            'perks' => ReferenceData::PERKS,
            'contact_modes' => ReferenceData::CONTACT_MODES,
            'industries' => ReferenceData::INDUSTRIES,
            'company_sizes' => ReferenceData::COMPANY_SIZES,
            'hiring_as' => ReferenceData::HIRING_AS,
            'interview_modes' => ReferenceData::INTERVIEW_MODES,
            'worker_sorts' => ReferenceData::WORKER_SORTS,
            // Verification screens: business types, the documents each needs,
            // number formats and what can stand in for a missing document.
            'verification' => KycRequirements::reference(),
        ]);
    }

    /**
     * Cities for a given state (dependent dropdown).
     */
    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate(['state' => ['required', 'string', 'max:100']]);

        return response()->json([
            'cities' => ReferenceData::citiesFor($data['state']),
        ]);
    }

    /**
     * Active job categories (admin-managed).
     */
    public function jobCategories(): JsonResponse
    {
        return response()->json(['job_categories' => $this->categories()]);
    }

    /**
     * @return list<string>
     */
    private function categories(): array
    {
        return array_values(Category::cachedActiveNames());
    }
}
