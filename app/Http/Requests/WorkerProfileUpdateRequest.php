<?php

namespace App\Http\Requests;

use App\Support\ReferenceData;
use App\Support\Wage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkerProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isWorker() ?? false;
    }

    /**
     * Wages are monthly. An expected wage sent per day or per hour, as older
     * app builds do, is stored as its monthly amount.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('expected_wage')) {
            $this->merge(['expected_wage' => Wage::monthly($this->input('expected_wage'), $this->input('wage_type'))]);
        }

        if ($this->has('expected_wage') || $this->has('wage_type')) {
            $this->merge(['wage_type' => Wage::MONTHLY]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Real email lives on the user record; handled by the controller.
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'skills' => ['nullable', 'array', 'max:30'],
            'skills.*' => ['string', 'max:50'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:70'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'expected_wage' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'wage_type' => ['nullable', 'string', Rule::in(ReferenceData::WAGE_TYPES)],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'available' => ['boolean'],
            'screening_calls_opted_out' => ['boolean'],
            'payout_upi' => ['nullable', 'string', 'max:100', 'regex:/^[\w.\-]{2,}@[a-zA-Z]{2,}$/'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
