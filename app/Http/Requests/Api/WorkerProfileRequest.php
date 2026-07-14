<?php

namespace App\Http\Requests\Api;

use App\Support\ReferenceData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the worker profile / registration payload sent as JSON from the
 * mobile app. The avatar is uploaded separately (multipart) to keep this
 * endpoint JSON-only.
 */
class WorkerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isWorker() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'skills' => ['nullable', 'array', 'max:30'],
            'skills.*' => ['string', 'max:50'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:70'],
            'education' => ['nullable', Rule::in(ReferenceData::EDUCATION_LEVELS)],
            'spoken_languages' => ['nullable', 'array', 'max:15'],
            'spoken_languages.*' => ['string', 'max:40'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'expected_wage' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'wage_type' => ['nullable', Rule::in(ReferenceData::WAGE_TYPES)],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'travel_radius_km' => ['nullable', 'integer', 'min:1', 'max:500'],
            'available' => ['boolean'],
            'payout_upi' => ['nullable', 'string', 'max:100', 'regex:/^[\w.\-]{2,}@[a-zA-Z]{2,}$/'],
        ];
    }
}
