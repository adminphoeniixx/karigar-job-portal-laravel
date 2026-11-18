<?php

namespace App\Http\Requests;

use App\Services\Geocoder;
use App\Support\ReferenceData;
use App\Support\Wage;
use Illuminate\Contracts\Validation\Validator;
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

        // Older app builds send neither: their jobs are on-site, as every job
        // was before. A remote job's interview defaults to remote too.
        if (! $this->filled('work_mode')) {
            $this->merge(['work_mode' => $this->route('job')?->work_mode ?? 'on_site']);
        }

        if (! $this->filled('interview_mode')) {
            $this->merge(['interview_mode' => $this->route('job')?->interview_mode
                ?? ($this->input('work_mode') === 'remote' ? 'remote' : 'on_site')]);
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

        $this->prepareSchedule();

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
            // Where the work is done, and how the interview is held.
            'work_mode' => ['required', 'string', Rule::in(ReferenceData::WORK_MODES)],
            'interview_mode' => ['required', 'string', Rule::in(ReferenceData::JOB_INTERVIEW_MODES)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'vacancies' => ['required', 'integer', 'min:1', 'max:10000'],
            'experience_min' => ['nullable', 'integer', 'min:0', 'max:60'],
            'experience_max' => ['nullable', 'integer', 'min:0', 'max:60', 'gte:experience_min'],
            // male | female | both
            'gender' => ['nullable', 'string', Rule::in(ReferenceData::GENDERS)],
            'shift' => ['nullable', 'string', 'in:'.implode(',', ReferenceData::SHIFTS)],
            // A day / night shift's hours, "HH:MM" 24-hour; a night shift may end before it starts.
            'shift_start' => ['nullable', 'date_format:H:i', 'required_with:shift_end'],
            'shift_end' => ['nullable', 'date_format:H:i', 'required_with:shift_start'],
            // Rotational: each shift's hours, 1st shift first.
            'shift_timings' => ['nullable', 'array', 'min:1', 'max:'.ReferenceData::MAX_SHIFT_TIMINGS],
            'shift_timings.*' => ['array:start,end'],
            'shift_timings.*.start' => ['required', 'date_format:H:i'],
            'shift_timings.*.end' => ['required', 'date_format:H:i', 'different:shift_timings.*.start'],
            // Flexible: total hours per day or per week, and an optional core
            // window when the karigar must be available, inside those hours.
            'work_hours' => ['nullable', 'integer', 'min:1', $this->input('work_hours_period') === 'week' ? 'max:168' : 'max:24', 'required_with:core_start,core_end'],
            'work_hours_period' => ['nullable', 'string', Rule::in(ReferenceData::WORK_HOURS_PERIODS), 'required_with:work_hours'],
            'core_start' => ['nullable', 'date_format:H:i', 'required_with:core_end'],
            'core_end' => ['nullable', 'date_format:H:i', 'required_with:core_start', 'different:core_start'],
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

    /**
     * A flexible job's core window must fit inside its working hours: 4 core
     * hours on an 8 hrs/day job, not 10.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['work_hours', 'work_hours_period', 'core_start', 'core_end'])) {
                return;
            }

            $start = $this->input('core_start');
            $end = $this->input('core_end');
            $hours = $this->input('work_hours');

            if (! is_string($start) || ! is_string($end) || ! is_numeric($hours)) {
                return;
            }

            $perDay = $this->input('work_hours_period') === 'week'
                ? $hours / ReferenceData::WORK_DAYS_PER_WEEK
                : (float) $hours;
            $core = self::minutesBetween($start, $end) / 60;

            if ($core > $perDay + 1e-9) {
                $validator->errors()->add('core_end', __('Core hours (:core hrs) cannot be more than the working hours in a day (:hours hrs).', [
                    'core' => round($core, 1),
                    'hours' => round($perDay, 1),
                ]));
            }
        });
    }

    /**
     * Minutes from one "HH:MM" to the next, past midnight if the end is earlier.
     */
    public static function minutesBetween(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        $minutes = ($eh * 60 + $em) - ($sh * 60 + $sm);

        return $minutes <= 0 ? $minutes + 24 * 60 : $minutes;
    }

    /**
     * Keeps only the hours that belong to the shift picked: a day job loses
     * any rotational timings or flexible hours left over from an earlier
     * choice. Fields an older app build does not know about are left alone,
     * so its edit does not wipe what a newer build saved. A flexible job's
     * hours default to per day.
     */
    private function prepareSchedule(): void
    {
        if (! $this->has('shift')) {
            return;
        }

        $shift = $this->input('shift');
        $merge = [];

        if (is_array($this->input('shift_timings'))) {
            $merge['shift_timings'] = array_values(array_filter(
                $this->input('shift_timings'),
                fn ($timing) => ! is_array($timing) || filled($timing['start'] ?? null) || filled($timing['end'] ?? null),
            )) ?: null;
        }

        if ($shift !== 'rotational') {
            $merge['shift_timings'] = null;
        } elseif (filled($merge['shift_timings'] ?? null)) {
            // The list replaces the single pair of times.
            $merge['shift_start'] = null;
            $merge['shift_end'] = null;
        }

        if ($shift !== 'flexible') {
            $merge += ['work_hours' => null, 'work_hours_period' => null, 'core_start' => null, 'core_end' => null];
        } else {
            if ($this->filled('work_hours') && ! $this->filled('work_hours_period')) {
                $merge['work_hours_period'] = 'day';
            }

            if ($this->has('work_hours') || $this->has('core_start')) {
                // No fixed shift: the hours and the core window say it all.
                $merge['shift_start'] = null;
                $merge['shift_end'] = null;
            }
        }

        $this->merge($merge);
    }
}
