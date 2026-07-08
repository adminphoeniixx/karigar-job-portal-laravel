<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkerProfileUpdateRequest extends FormRequest
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
            'phone' => ['nullable', 'string', 'max:20'],
            'skills' => ['nullable', 'array', 'max:30'],
            'skills.*' => ['string', 'max:50'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:70'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'expected_wage' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'wage_type' => ['nullable', 'string', 'in:hourly,daily,monthly'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'available' => ['boolean'],
            'payout_upi' => ['nullable', 'string', 'max:100', 'regex:/^[\w.\-]{2,}@[a-zA-Z]{2,}$/'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
