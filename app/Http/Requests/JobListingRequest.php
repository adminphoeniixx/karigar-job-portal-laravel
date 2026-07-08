<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isEmployer() ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Older clients (and existing drafts) may not send a contact mode.
        if (! $this->filled('contact_mode')) {
            $this->merge(['contact_mode' => 'apply']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'skills' => ['nullable', 'array', 'max:30'],
            'skills.*' => ['string', 'max:50'],
            'wage_min' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'wage_max' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'gte:wage_min'],
            'wage_type' => ['nullable', 'string', 'in:hourly,daily,monthly'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'vacancies' => ['required', 'integer', 'min:1', 'max:10000'],
            'contact_mode' => ['required', 'string', 'in:apply,call,both'],
            'contact_phone' => ['nullable', 'string', 'max:20', 'required_if:contact_mode,call', 'required_if:contact_mode,both'],
            'status' => ['required', 'string', 'in:draft,active,closed'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }
}
