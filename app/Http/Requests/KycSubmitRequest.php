<?php

namespace App\Http\Requests;

use App\Models\KycDocument;
use App\Support\KycRequirements;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Verification submission, worker or employer. Which documents are asked for
 * comes from {@see KycRequirements}; for each one the user either gives its
 * number + a photo, or sets `{doc}_missing` and offers an alternate ID, its
 * photo and a reason instead.
 *
 * A photo already on file need not be sent again on a re-submission.
 */
class KycSubmitRequest extends FormRequest
{
    private ?KycDocument $existing = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function prepareForValidation(): void
    {
        $merge = [];

        foreach (['pan_number', 'gstin'] as $field) {
            if ($this->filled($field)) {
                $merge[$field] = strtoupper(preg_replace('/\s+/', '', (string) $this->input($field)));
            }
        }

        if ($this->filled('aadhaar_number')) {
            $merge['aadhaar_number'] = preg_replace('/\D+/', '', (string) $this->input('aadhaar_number'));
        }

        $this->merge($merge);
    }

    /**
     * Documents this submission has to cover.
     *
     * @return list<string>
     */
    public function documents(): array
    {
        return KycRequirements::documentsFor($this->role(), $this->businessType());
    }

    public function role(): string
    {
        return $this->user()?->isEmployer() ? 'employer' : 'worker';
    }

    public function businessType(): ?string
    {
        if ($this->role() !== 'employer') {
            return null;
        }

        $type = $this->input('business_type');

        return is_string($type) && array_key_exists($type, KycRequirements::BUSINESS_TYPES) ? $type : null;
    }

    public function isMissing(string $doc): bool
    {
        return $this->boolean($doc.'_missing');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kyc = $this->existing();
        $rules = [];

        if ($this->role() === 'employer') {
            $rules['business_type'] = ['required', 'string', Rule::in(array_keys(KycRequirements::BUSINESS_TYPES))];
            $rules['legal_name'] = ['required', 'string', 'max:150'];
            $rules['registered_address'] = ['required', 'string', 'max:500'];
        }

        foreach ($this->documents() as $doc) {
            $spec = KycRequirements::DOCUMENTS[$doc];
            $rules[$doc.'_missing'] = ['nullable', 'boolean'];

            if ($this->isMissing($doc)) {
                $hasAltDoc = ($kyc?->missing_documents[$doc]['doc_path'] ?? null) !== null;

                $rules[$doc.'_alt_type'] = ['required', 'string', Rule::in(array_keys(KycRequirements::ALTERNATES[$doc]))];
                $rules[$doc.'_alt_number'] = ['nullable', 'string', 'max:50'];
                $rules[$doc.'_alt_doc'] = [$hasAltDoc ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'];
                $rules[$doc.'_reason'] = ['required', 'string', 'max:500'];

                continue;
            }

            $hasDoc = $kyc !== null && KycDocument::pathFor($kyc, $doc) !== null;

            $rules[$spec['number_field']] = ['required', 'string', 'regex:/'.$spec['pattern'].'/'];
            $rules[$doc.'_doc'] = [$hasDoc ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'];
        }

        return $rules;
    }

    /**
     * A GSTIN carries the holder's PAN in characters 3–12, so the two must agree.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $docs = $this->documents();

            if (! in_array('gst', $docs, true) || $this->isMissing('gst') || $this->isMissing('pan')) {
                return;
            }

            $gstin = (string) $this->input('gstin');
            $pan = (string) $this->input('pan_number');

            if (strlen($gstin) === 15 && strlen($pan) === 10 && substr($gstin, 2, 10) !== $pan) {
                $validator->errors()->add('gstin', __('This GSTIN does not belong to the PAN given above.'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pan_number.regex' => 'PAN must be in the format ABCDE1234F.',
            'aadhaar_number.regex' => 'Aadhaar must be exactly 12 digits.',
            'gstin.regex' => 'GSTIN must be in the format 22ABCDE1234F1Z5.',
            '*_alt_type.required' => 'Pick which document you are sending instead.',
            '*_alt_doc.required' => 'Upload a photo of the document you are sending instead.',
            '*_reason.required' => 'Tell us why you do not have this document.',
        ];
    }

    private function existing(): ?KycDocument
    {
        $user = $this->user();
        $account = $user?->isEmployer() ? $user->employerAccount() : $user;

        return $this->existing ??= $account?->kyc()->first();
    }
}
