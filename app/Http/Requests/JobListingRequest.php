<?php

namespace App\Http\Requests;

use App\Services\Geocoder;
use App\Support\ReferenceData;
use App\Support\Wage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        if (! $this->has('requires_worker_fee')) {
            $this->merge(['requires_worker_fee' => false]);
        }

        // A draft is somewhere to leave a half-written job, so it needs only a
        // title. The columns behind these two are not nullable; they are
        // filled in properly when the job is published, which validates them.
        if ($this->input('status') === 'draft') {
            if (! $this->filled('description')) {
                $this->merge(['description' => '']);
            }
            if (! $this->filled('vacancies')) {
                $this->merge(['vacancies' => 1]);
            }
        }

        // Ignore any stale amount when no fee is charged.
        if (! $this->boolean('requires_worker_fee')) {
            $this->merge(['worker_fee_amount' => null]);
        }

        if (is_array($this->input('perks'))) {
            $this->merge(['perks' => collect($this->input('perks'))
                ->map(fn ($perk) => is_string($perk) ? trim($perk) : $perk)
                ->filter(fn ($perk) => $perk !== '' && $perk !== null)
                ->unique(fn ($perk) => is_string($perk) ? mb_strtolower($perk) : $perk)
                ->values()
                ->all()]);
        }

        // Wages are monthly. One sent per day or per hour, as older app builds
        // do, is stored as its monthly amount.
        $period = $this->input('wage_type');

        foreach (['wage_min', 'wage_max'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => Wage::monthly($this->input($field), $period)]);
            }
        }

        if ($this->filled('wage_min') || $this->filled('wage_max') || $this->has('wage_type')) {
            $this->merge(['wage_type' => Wage::MONTHLY]);
        }

        // No map pin from the client (the employer app sends an address but no
        // coordinates): place it from the address, or from the city and state.
        if (! $this->filled('latitude') && ! $this->filled('longitude') && ($this->filled('address') || $this->filled('city'))) {
            $pin = app(Geocoder::class)->locate($this->input('address'), $this->input('city'), $this->input('state'));

            if ($pin !== null) {
                $this->merge(['latitude' => $pin[0], 'longitude' => $pin[1]]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => $this->input('status') === 'draft'
                ? ['present', 'nullable', 'string', 'max:5000']
                : ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'skills' => ['nullable', 'array', 'max:30'],
            'skills.*' => ['string', 'max:50'],
            'wage_min' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'wage_max' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'gte:wage_min'],
            'wage_type' => ['nullable', 'string', Rule::in(ReferenceData::WAGE_TYPES)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'vacancies' => ['required', 'integer', 'min:1', 'max:10000'],
            'experience_min' => ['nullable', 'integer', 'min:0', 'max:60'],
            'experience_max' => ['nullable', 'integer', 'min:0', 'max:60', 'gte:experience_min'],
            'shift' => ['nullable', 'string', 'in:'.implode(',', ReferenceData::SHIFTS)],
            // The shift's hours, "HH:MM" 24-hour; a night shift may end before it starts.
            'shift_start' => ['nullable', 'date_format:H:i', 'required_with:shift_end'],
            'shift_end' => ['nullable', 'date_format:H:i', 'required_with:shift_start'],
            // Any perk: ReferenceData::PERKS are only suggestions.
            'perks' => ['nullable', 'array', 'max:15'],
            'perks.*' => ['string', 'max:40'],
            'requires_worker_fee' => ['required', 'boolean'],
            'worker_fee_amount' => ['nullable', 'numeric', 'min:1', 'max:1000000', 'required_if:requires_worker_fee,true'],
            'contact_mode' => ['required', 'string', 'in:apply,call,both'],
            'contact_phone' => ['nullable', 'string', 'max:20', 'required_if:contact_mode,call', 'required_if:contact_mode,both'],
            // Who picks up when a karigar calls.
            'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_designation' => ['nullable', 'string', 'max:100'],
            // The employer's AI switches for this job. Optional: left out, a
            // new job gets both on and an edit keeps what it had.
            'ai_shortlist_enabled' => ['sometimes', 'boolean'],
            'ai_call_enabled' => ['sometimes', 'boolean'],
            'status' => ['required', 'string', 'in:draft,active,closed'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }
}
