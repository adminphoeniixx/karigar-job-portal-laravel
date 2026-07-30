<?php

namespace App\Http\Requests;

use App\Support\ReferenceData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployerProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isEmployer() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Contact-person name and email live on the user record, handled by the controller.
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'company_name' => ['nullable', 'string', 'max:255'],
            'hiring_as' => ['nullable', 'string', Rule::in(ReferenceData::HIRING_AS)],
            'industry' => ['nullable', 'string', 'max:120'],
            'company_size' => ['nullable', 'string', Rule::in(ReferenceData::COMPANY_SIZES)],
            'hiring_categories' => ['nullable', 'array', 'max:20'],
            'hiring_categories.*' => ['string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'about' => ['nullable', 'string', 'max:2000'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]{3}$/i'],
        ];
    }
}
