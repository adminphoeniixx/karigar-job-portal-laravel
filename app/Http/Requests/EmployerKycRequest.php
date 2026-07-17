<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Business verification for employers: GSTIN + business PAN with proof docs.
 */
class EmployerKycRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isEmployer() ?? false;
    }

    public function prepareForValidation(): void
    {
        if ($this->filled('gstin')) {
            $this->merge(['gstin' => strtoupper(trim((string) $this->input('gstin')))]);
        }
        if ($this->filled('pan_number')) {
            $this->merge(['pan_number' => strtoupper(trim((string) $this->input('pan_number')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hasExisting = (bool) $this->user()?->kyc()?->exists();

        return [
            'gstin' => ['required', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]{3}$/'],
            'pan_number' => ['required', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'gst_doc' => [$hasExisting ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'pan_doc' => [$hasExisting ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gstin.regex' => 'GSTIN must be in the format 22ABCDE1234F1Z5.',
            'pan_number.regex' => 'PAN must be in the format ABCDE1234F.',
        ];
    }
}
