<?php

namespace App\Http\Requests;

use App\Models\KycCheck;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\KycAutoVerifier;
use App\Support\KycRequirements;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Verification submission, worker or employer. Which documents are asked for
 * comes from {@see KycRequirements}; for each one the user either gives its
 * number, or sets `{doc}_missing` and offers an alternate ID and its photo
 * instead. An Aadhaar verified through DigiLocker needs no number at all.
 *
 * An employer must send a photo / PDF of every document and the number of an
 * alternate ID; a karigar's photo and alternate number stay optional (the
 * worker app does not ask for them). No reason is asked for a missing document
 * any more: `{doc}_reason` is still accepted from older app builds.
 */
class KycSubmitRequest extends FormRequest
{
    private ?KycDocument $existing = null;

    private KycCheck|false|null $digilocker = null;

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
        // Employers send proof of everything; karigars keep the lighter form.
        $strict = KycRequirements::proofRequired($this->role());

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
                $rules[$doc.'_alt_number'] = [$strict ? 'required' : 'nullable', 'string', 'max:50'];
                $rules[$doc.'_alt_doc'] = [$hasAltDoc ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'];
                $rules[$doc.'_reason'] = ['nullable', 'string', 'max:500'];

                continue;
            }

            // An Aadhaar already verified through DigiLocker needs no number.
            $aadhaarViaDigilocker = $doc === 'aadhaar' && $this->digilockerAadhaar() !== null;
            // No photo either for what DigiLocker vouches for: that Aadhaar,
            // or a PAN that is the one DigiLocker holds (approved on the spot).
            $vouched = $aadhaarViaDigilocker || ($doc === 'pan' && $this->panMatchesDigilocker());
            // A photo already on file (a resubmission) need not be sent again.
            $hasDoc = $kyc?->{KycDocument::pathColumn($doc)} !== null;

            $rules[$spec['number_field']] = [$aadhaarViaDigilocker ? 'nullable' : 'required', 'string', 'regex:/'.$spec['pattern'].'/'];
            $rules[$doc.'_doc'] = [$strict && ! $vouched && ! $hasDoc ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'];
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
            '*_alt_number.required' => 'Enter the number of the document you are sending instead.',
            '*_alt_doc.required' => 'Upload a photo of the document you are sending instead.',
            '*_doc.required' => 'Upload a photo or PDF of this document.',
        ];
    }

    /**
     * The account being verified: an employer's team member verifies the owner's.
     */
    public function account(): ?User
    {
        $user = $this->user();

        return $user?->isEmployer() ? $user->employerAccount() : $user;
    }

    /**
     * The Aadhaar this account already verified through DigiLocker, if any.
     */
    public function digilockerAadhaar(): ?KycCheck
    {
        if ($this->digilocker !== null) {
            return $this->digilocker ?: null;
        }

        $account = $this->account();
        $check = $account ? KycAutoVerifier::digilockerAadhaar($account) : null;
        $this->digilocker = $check ?? false;

        return $check;
    }

    /**
     * Whether the PAN typed is the one this account's DigiLocker sign-in shared.
     */
    private function panMatchesDigilocker(): bool
    {
        $pan = $this->digilockerAadhaar()?->details['pan'] ?? null;

        return $pan !== null && $pan === $this->input('pan_number');
    }

    private function existing(): ?KycDocument
    {
        return $this->existing ??= $this->account()?->kyc()->first();
    }
}
