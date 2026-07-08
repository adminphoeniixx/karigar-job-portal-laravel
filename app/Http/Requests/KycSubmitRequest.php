<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KycSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function prepareForValidation(): void
    {
        if ($this->filled('pan_number')) {
            $this->merge(['pan_number' => strtoupper(trim((string) $this->input('pan_number')))]);
        }
        if ($this->filled('aadhaar_number')) {
            $this->merge(['aadhaar_number' => preg_replace('/\s+/', '', (string) $this->input('aadhaar_number'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hasExisting = (bool) $this->user()?->kyc()?->exists();

        return [
            'pan_number' => ['required', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'aadhaar_number' => ['required', 'string', 'regex:/^\d{12}$/'],
            'pan_doc' => [$hasExisting ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
            'aadhaar_doc' => [$hasExisting ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pan_number.regex' => 'PAN must be in the format ABCDE1234F.',
            'aadhaar_number.regex' => 'Aadhaar must be exactly 12 digits.',
        ];
    }
}
