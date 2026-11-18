<?php

namespace App\Http\Requests;

use App\Support\ReferenceData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The business profile, saved a step at a time during sign-up and from Edit
 * profile, so every field is optional. Except on "Finish setup": the app sends
 * `finish: true` and then the profile must be whole — contact name, hiring as,
 * business name (not for an individual), industry, at least one kind of
 * karigar, city and state, counting what is already saved. "Skip for now"
 * simply does not send `finish`.
 */
class EmployerProfileUpdateRequest extends FormRequest
{
    /** Fields "Finish setup" needs, each either sent now or already saved. */
    public const FINISH_REQUIRED = ['name', 'hiring_as', 'company_name', 'industry', 'hiring_categories', 'city', 'state'];

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
            'finish' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('finish')) {
                return;
            }

            foreach ($this->missingForFinish() as $field) {
                if (! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, self::finishMessage($field));
                }
            }
        });
    }

    /**
     * Required fields neither sent in this request nor already saved.
     *
     * @return list<string>
     */
    public function missingForFinish(): array
    {
        $account = $this->user()->employerAccount();
        $profile = $account->employerProfile()->first();
        $hiringAs = $this->input('hiring_as', $profile?->hiring_as);

        $saved = [
            'name' => $account->name,
            'hiring_as' => $profile?->hiring_as,
            'company_name' => $profile?->company_name,
            'industry' => $profile?->industry,
            'hiring_categories' => $profile?->hiring_categories,
            'city' => $profile?->city,
            'state' => $profile?->state,
        ];

        return array_values(array_filter(self::FINISH_REQUIRED, function (string $field) use ($saved, $hiringAs): bool {
            // Someone hiring for their own home has no business name.
            if ($field === 'company_name' && $hiringAs === 'individual') {
                return false;
            }

            $value = $this->exists($field) ? $this->input($field) : $saved[$field];

            return is_array($value) ? array_filter($value, 'filled') === [] : blank($value);
        }));
    }

    private static function finishMessage(string $field): string
    {
        return match ($field) {
            'name' => __('Enter your name.'),
            'hiring_as' => __('Choose who you are hiring as.'),
            'company_name' => __('Enter your business name.'),
            'industry' => __('Choose your industry.'),
            'hiring_categories' => __('Pick at least one kind of karigar you hire.'),
            'city' => __('Choose your city.'),
            'state' => __('Choose your state.'),
            default => __('This field is required.'),
        };
    }
}
