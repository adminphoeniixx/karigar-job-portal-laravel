<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\ReferenceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
            'skills' => ReferenceData::SKILLS,
            'spoken_languages' => ReferenceData::SPOKEN_LANGUAGES,
            'education_levels' => ReferenceData::EDUCATION_LEVELS,
            'wage_types' => ReferenceData::WAGE_TYPES,
            'app_languages' => ReferenceData::APP_LANGUAGES,
            'job_categories' => $this->categories(),
            // Employer-app dropdowns.
            'shifts' => ReferenceData::SHIFTS,
            'perks' => ReferenceData::PERKS,
            'contact_modes' => ReferenceData::CONTACT_MODES,
            'industries' => ReferenceData::INDUSTRIES,
            'company_sizes' => ReferenceData::COMPANY_SIZES,
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
        return array_values(Cache::rememberForever('categories.active', fn () => Category::activeNames()));
    }
}
