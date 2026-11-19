<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Support\JobDetailLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;
use Throwable;

/**
 * @property int $id
 * @property int $employer_id
 * @property string $title
 * @property string $description
 * @property string|null $category
 * @property array<int, string>|null $skills
 * @property string|null $wage_min
 * @property string|null $wage_max
 * @property string|null $wage_type
 * @property int|null $experience_max
 * @property string|null $shift_start
 * @property string|null $shift_end
 * @property list<array{start: string, end: string}>|null $shift_timings
 * @property int|null $work_hours
 * @property string|null $work_hours_period
 * @property string|null $core_start
 * @property string|null $core_end
 * @property string|null $gender
 * @property string|null $contact_name
 * @property string|null $contact_designation
 * @property int|null $reposted_from_id
 * @property string|null $city
 * @property string|null $state
 * @property string $work_mode on_site | remote | both
 * @property string $interview_mode on_site | remote
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int $vacancies
 * @property int|null $experience_min
 * @property int $views_count
 * @property string|null $boost_tier
 * @property Carbon|null $boosted_until
 * @property string|null $shift
 * @property array<int, string>|null $perks
 * @property bool $requires_worker_fee
 * @property string|null $worker_fee_amount
 * @property string $contact_mode
 * @property string|null $contact_phone
 * @property JobStatus $status
 * @property Carbon|null $expires_at
 */
class JobListing extends Model
{
    use Searchable;

    protected $fillable = [
        'title', 'description', 'category', 'skills',
        'wage_min', 'wage_max', 'wage_type',
        'address', 'city', 'state', 'latitude', 'longitude', 'work_mode', 'interview_mode',
        'vacancies', 'experience_min', 'experience_max', 'status', 'published_at', 'expires_at',
        'contact_mode', 'contact_phone', 'contact_name', 'contact_designation',
        'shift', 'shift_start', 'shift_end', 'shift_timings', 'work_hours', 'work_hours_period', 'core_start', 'core_end',
        'gender', 'perks', 'reposted_from_id',
        'requires_worker_fee', 'worker_fee_amount', 'worker_fee_purpose',
        // The WorkIndia-style details (2026-11-19).
        'job_type', 'education', 'english', 'age_min', 'age_max', 'languages', 'assets',
        'reach', 'relocation_allowed', 'incentive_max', 'incentive_type', 'work_days',
        'interview_days', 'interview_start', 'interview_end', 'interview_address',
        'hiring_urgency', 'other_requirements',
        'ai_shortlist_enabled', 'ai_call_enabled',
        'boost_tier', 'boosted_until',
        'moderation_status', 'moderation_reasons', 'moderation_note', 'moderation_method', 'moderated_at', 'moderated_by',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'perks' => 'array',
            'shift_timings' => 'array',
            'work_hours' => 'integer',
            'experience_min' => 'integer',
            'experience_max' => 'integer',
            'views_count' => 'integer',
            'boosted_until' => 'datetime',
            'requires_worker_fee' => 'boolean',
            'languages' => 'array',
            'assets' => 'array',
            'work_days' => 'array',
            'interview_days' => 'array',
            'relocation_allowed' => 'boolean',
            'age_min' => 'integer',
            'age_max' => 'integer',
            'incentive_max' => 'decimal:2',
            'ai_shortlist_enabled' => 'boolean',
            'ai_call_enabled' => 'boolean',
            'worker_fee_amount' => 'decimal:2',
            'status' => JobStatus::class,
            'expires_at' => 'datetime',
            'published_at' => 'datetime',
            'moderation_reasons' => 'array',
            'moderated_at' => 'datetime',
            'wage_min' => 'decimal:2',
            'wage_max' => 'decimal:2',
        ];
    }

    /**
     * Stamp the moment a job first goes live, whichever path takes it there —
     * posted straight away, a draft published, or an admin activating it.
     * Drafts have no stamp, and closing and reopening keeps the first one, so
     * the plan's job-post limit counts each job once.
     */
    protected static function booted(): void
    {
        static::saving(function (JobListing $job) {
            if ($job->status === JobStatus::Active && $job->published_at === null) {
                $job->published_at = now();
            }

            if ($job->published_at !== null && ($job->isDirty('published_at') || $job->isDirty('expires_at'))) {
                $job->capExpiryToPlan();
            }
        });

        // A title no category lists goes to Admin → Categories, where it can
        // join one. That runs after the commit and never stops the job saving:
        // a failed query inside a Postgres transaction would sink the whole save.
        static::saved(function (JobListing $job) {
            if ($job->wasRecentlyCreated || $job->wasChanged('title')) {
                $title = (string) $job->title;
                DB::afterCommit(fn () => rescue(fn () => CustomJobTitle::record($title)));
            }
        });
    }

    /**
     * A job runs for its plan's job-live days from going live (15 on the
     * apna-style plans). An earlier date the employer picked stands; a later
     * one is pulled back. Without a job plan (the free first post) the job
     * runs as long as the employer said.
     */
    private function capExpiryToPlan(): void
    {
        $plan = $this->employer?->employerAccount()->activeSubscription()?->plan;

        if ($plan === null) {
            return;
        }

        $latest = $this->published_at->copy()->addDays($plan->jobLiveDays());

        if ($this->expires_at === null || $this->expires_at->greaterThan($latest)) {
            $this->expires_at = $latest;
        }
    }

    public function isDraft(): bool
    {
        return $this->status === JobStatus::Draft;
    }

    public function searchableAs(): string
    {
        return 'job_listings';
    }

    /**
     * The experience asked for, as shown: "2–5 yrs", "2+ yrs", "Up to 5 yrs",
     * "Freshers welcome", or null when the job does not say.
     */
    public function experienceLabel(): ?string
    {
        $min = $this->experience_min;
        $max = $this->experience_max;

        return match (true) {
            $min === 0 && $max === 0 => __('Freshers only'),
            $min !== null && $max !== null && $min === $max => trans_choice(':count yr|:count yrs', $min, ['count' => $min]),
            $min !== null && $max !== null => __(':min–:max yrs', ['min' => $min, 'max' => $max]),
            $min === 0 => __('Freshers welcome'),
            $min !== null => __(':min+ yrs', ['min' => $min]),
            $max !== null => __('Up to :max yrs', ['max' => $max]),
            default => null,
        };
    }

    /**
     * The job's hours in one line, or null without any:
     * day / night "9:00 AM – 6:00 PM";
     * rotational "1st 6:00 AM – 2:00 PM · 2nd 2:00 PM – 10:00 PM";
     * flexible "8 hrs/day · Core 12:00 PM – 4:00 PM".
     */
    public function shiftHoursLabel(): ?string
    {
        $range = fn (string $start, string $end): string => self::time12($start).' – '.self::time12($end);

        if ($this->shift === 'rotational' && filled($this->shift_timings)) {
            return collect($this->shift_timings)
                ->values()
                ->map(fn (array $t, int $i) => self::ordinal($i + 1).' '.$range($t['start'], $t['end']))
                ->join(' · ');
        }

        if ($this->shift === 'flexible' && ($this->work_hours || $this->core_start)) {
            return collect([
                $this->work_hours
                    ? ($this->work_hours_period === 'week'
                        ? __(':count hrs/week', ['count' => $this->work_hours])
                        : __(':count hrs/day', ['count' => $this->work_hours]))
                    : null,
                $this->core_start && $this->core_end
                    ? __('Core :range', ['range' => $range($this->core_start, $this->core_end)])
                    : null,
            ])->filter()->join(' · ');
        }

        if (! $this->shift_start || ! $this->shift_end) {
            return null;
        }

        return $range($this->shift_start, $this->shift_end);
    }

    /**
     * Each shift of a rotational job with its name, for the apps to list:
     * [{"name": "1st shift", "start": "06:00", "end": "14:00", "label": "6:00 AM – 2:00 PM"}].
     *
     * @return list<array{name: string, start: string, end: string, label: string}>
     */
    public function shiftTimingsList(): array
    {
        if ($this->shift !== 'rotational') {
            return [];
        }

        return collect($this->shift_timings ?? [])
            ->values()
            ->map(fn (array $t, int $i) => [
                'name' => __(':nth shift', ['nth' => self::ordinal($i + 1)]),
                'start' => $t['start'],
                'end' => $t['end'],
                'label' => self::time12($t['start']).' – '.self::time12($t['end']),
            ])
            ->all();
    }

    public function genderLabel(): ?string
    {
        return $this->gender !== null ? self::genderLabelFor($this->gender) : null;
    }

    public static function genderLabelFor(string $gender): ?string
    {
        return match ($gender) {
            'male' => __('Male'),
            'female' => __('Female'),
            'both' => __('Male & female'),
            default => null,
        };
    }

    /**
     * any | fresher | experienced: the three choices the job form shows,
     * read from the experience range. A range that lets freshers in but
     * caps the years ("0–3 yrs") reads as experienced, so its numbers show.
     */
    public function experienceLevel(): string
    {
        return match (true) {
            $this->experience_min === null && $this->experience_max === null => 'any',
            $this->experience_min === 0 && $this->experience_max === 0 => 'fresher',
            default => 'experienced',
        };
    }

    /**
     * The new details' labels in one place, for the resources and the web
     * pages: null for whatever the job does not say.
     *
     * @return array<string, mixed>
     */
    public function detailLabels(): array
    {
        return [
            'job_type_label' => JobDetailLabels::jobType($this->job_type),
            'education_label' => JobDetailLabels::education($this->education),
            'english_label' => JobDetailLabels::english($this->english),
            'age_label' => JobDetailLabels::age($this->age_min, $this->age_max),
            'reach_label' => JobDetailLabels::reach($this->reach, $this->city),
            'incentive_label' => JobDetailLabels::incentive($this->incentive_max, $this->incentive_type),
            'worker_fee_purpose_label' => $this->requires_worker_fee ? JobDetailLabels::feePurpose($this->worker_fee_purpose) : null,
            'asset_labels' => array_map(JobDetailLabels::asset(...), array_values($this->assets ?? [])),
            'work_days_label' => JobDetailLabels::days($this->work_days),
            'interview_days_label' => JobDetailLabels::days($this->interview_days),
            'interview_hours_label' => JobDetailLabels::hours($this->interview_start, $this->interview_end),
            'hiring_urgency_label' => JobDetailLabels::urgency($this->hiring_urgency),
        ];
    }

    /**
     * The details added on 2026-11-19 with their labels, as both apps read
     * them (EmployerJobResource, JobDetailResource).
     *
     * @return array<string, mixed>
     */
    public function detailFields(): array
    {
        return [
            // full_time | part_time
            'job_type' => $this->job_type ?? 'full_time',
            // any | fresher | experienced, read from experience_min / max.
            'experience_level' => $this->experienceLevel(),
            // The lowest education taken ("or above"), one of education_levels.
            'education' => $this->education,
            // none | basic | good | fluent
            'english' => $this->english,
            'age_min' => $this->age_min,
            'age_max' => $this->age_max,
            'languages' => array_values($this->languages ?? []),
            'assets' => array_values($this->assets ?? []),
            // nearby (within 10 km of the pin) | city; null = city.
            'reach' => $this->reach,
            'relocation_allowed' => (bool) $this->relocation_allowed,
            'incentive_max' => $this->incentive_max,
            'incentive_type' => $this->incentive_type,
            'worker_fee_purpose' => $this->requires_worker_fee ? $this->worker_fee_purpose : null,
            'work_days' => array_values($this->work_days ?? []),
            'interview_days' => array_values($this->interview_days ?? []),
            'interview_start' => $this->interview_start,
            'interview_end' => $this->interview_end,
            // null = at the job address.
            'interview_address' => $this->interview_address,
            // immediate | can_wait
            'hiring_urgency' => $this->hiring_urgency,
            'other_requirements' => $this->other_requirements,
            ...$this->detailLabels(),
        ];
    }

    /** `detail_labels`, for the web pages that send the model as it is (forPublicPage). */
    public function getDetailLabelsAttribute(): array
    {
        return $this->detailLabels();
    }

    /** `experience_level`, likewise. */
    public function getExperienceLevelAttribute(): string
    {
        return $this->experienceLevel();
    }

    /** `shift_hours_label`, for the web pages that send the model as it is (forPublicPage). */
    public function getShiftHoursLabelAttribute(): ?string
    {
        return $this->shiftHoursLabel();
    }

    /** `gender_label`, likewise. */
    public function getGenderLabelAttribute(): ?string
    {
        return $this->genderLabel();
    }

    private static function time12(string $time): string
    {
        return Carbon::createFromFormat('H:i', $time)->format('g:i A');
    }

    private static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }

    public const WORK_ON_SITE = 'on_site';

    public const WORK_REMOTE = 'remote';

    public const WORK_BOTH = 'both';

    public const INTERVIEW_ON_SITE = 'on_site';

    public const INTERVIEW_REMOTE = 'remote';

    /** The karigar picks on the AI call: in person or on a phone/video call. */
    public const INTERVIEW_BOTH = 'both';

    /**
     * Where the work is done, as shown: "On-site", "Work from home", "On-site
     * or from home".
     */
    public function workModeLabel(): string
    {
        return self::workModeLabelFor($this->work_mode);
    }

    public static function workModeLabelFor(?string $mode): string
    {
        return match ($mode) {
            self::WORK_REMOTE => __('Work from home'),
            self::WORK_BOTH => __('On-site or from home'),
            default => __('On-site'),
        };
    }

    /**
     * How the interview is held, as shown.
     */
    public function interviewModeLabel(): string
    {
        return self::interviewModeLabelFor($this->interview_mode);
    }

    public static function interviewModeLabelFor(?string $mode): string
    {
        return match ($mode) {
            self::INTERVIEW_REMOTE => __('Interview on phone / video'),
            self::INTERVIEW_BOTH => __('Interview in person or on phone / video'),
            default => __('Interview in person'),
        };
    }

    /**
     * The mode a booked interview starts from for this job: in person at the
     * site, or a phone call for a remote interview (the employer can still
     * pick video). A job that offers both starts in person; the mode the
     * karigar chose on the AI call overrides this.
     */
    public function bookedInterviewMode(): string
    {
        return $this->interview_mode === self::INTERVIEW_REMOTE ? 'phone' : 'site';
    }

    /**
     * Only jobs taking applications are indexed for public search.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->isOpenForApplications();
    }

    /**
     * Active, unexpired, and the employer is hiring: its job plan has not run
     * out ({@see User::jobPlanLapsed()}).
     */
    public function isOpenForApplications(): bool
    {
        return $this->status === JobStatus::Active
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ! $this->isHiringPaused();
    }

    /**
     * Whether this user may open the job page. A job that is not taking
     * applications (paused by a lapsed plan, expired or closed) is gone for
     * everyone, except its own employer, admins, and workers who already
     * applied and still want to see where they stand.
     */
    public function isViewableBy(?User $user): bool
    {
        if ($this->isOpenForApplications()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $user->isAdmin()
            || ($user->isEmployer() && $user->employerAccount()->id === $this->employer_id)
            || $this->applications()->where('worker_id', $user->id)->exists();
    }

    /**
     * The job as a visitor or a karigar may see it on the web pages, which
     * hand the whole model to the browser. Moderation notes and the
     * employer's AI switches are not theirs to read, the employer's KYC record
     * is not either, and the contact person is shown only on a job that takes
     * calls: the same rule the app's JobDetailResource applies.
     */
    public function forPublicPage(): static
    {
        $this->makeHidden([
            'moderation_status', 'moderation_reasons', 'moderation_note', 'moderation_method', 'moderated_at', 'moderated_by',
            'ai_shortlist_enabled', 'ai_call_enabled', 'applicants_released', 'reposted_from_id', 'views_count',
        ]);

        if ($this->contact_mode === 'apply') {
            $this->makeHidden(['contact_phone', 'contact_name', 'contact_designation']);
        }

        // The hours in one line, whatever the shift type, who it is for, and
        // the labels of the details added on 2026-11-19.
        $this->append(['shift_hours_label', 'gender_label', 'detail_labels', 'experience_level']);

        if ($this->relationLoaded('employer')) {
            $this->employer?->unsetRelation('kyc');
        }

        return $this;
    }

    /**
     * The employer's job plan ran out: the job is out of search and closed to
     * applications until the plan is renewed.
     */
    public function isHiringPaused(): bool
    {
        return (bool) $this->employer?->jobPlanLapsed();
    }

    /**
     * Put an employer's live jobs back in search, or take them out, to match
     * whether it is hiring. Called when a job plan starts or renews, and by
     * `jobs:sync-hiring` for plans that ran out. A search outage must never
     * fail the payment or command that called it.
     */
    public static function syncSearchFor(User $employer): void
    {
        try {
            [$open, $paused] = $employer->jobListings()->active()->get()->partition->shouldBeSearchable();
            $open->searchable();
            $paused->unsearchable();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $data = [
            'id' => (string) $this->id,
            'title' => (string) $this->title,
            'description' => (string) $this->description,
            'category' => $this->category,
            'skills' => $this->skills ?? [],
            'city' => $this->city,
            'state' => $this->state,
            'wage_max' => $this->wage_max !== null ? (float) $this->wage_max : null,
            'boosted' => $this->isBoosted(),
            'created_at' => $this->created_at?->timestamp ?? now()->timestamp,
        ];

        if ($this->latitude !== null && $this->longitude !== null) {
            $data['location'] = [(float) $this->latitude, (float) $this->longitude];
        }

        return $data;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    /**
     * Is this job currently promoted (boost still running)?
     */
    public function isBoosted(): bool
    {
        return $this->boosted_until !== null && $this->boosted_until->isFuture();
    }

    /**
     * A ready-to-display wage string, e.g. "₹900 – ₹1,200 / monthly",
     * "₹900 / monthly", or "Not disclosed" when no wage is set. Kept here so the
     * web, worker app and employer app all render the wage the same way.
     */
    public function wageLabel(): string
    {
        $min = $this->wage_min !== null ? (float) $this->wage_min : null;
        $max = $this->wage_max !== null ? (float) $this->wage_max : null;

        if ($min === null && $max === null) {
            return __('Not disclosed');
        }

        $money = fn (float $n): string => '₹'.number_format($n);
        $suffix = $this->wage_type ? ' / '.$this->wage_type : '';

        if ($min !== null && $max !== null) {
            $amount = $min === $max ? $money($min) : $money($min).' – '.$money($max);
        } else {
            $amount = $money($min ?? $max);
        }

        return $amount.$suffix;
    }

    /**
     * @return HasMany<JobInvite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(JobInvite::class);
    }

    /**
     * @return HasMany<JobApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    /**
     * @return HasMany<SavedJob, $this>
     */
    public function savedBy(): HasMany
    {
        return $this->hasMany(SavedJob::class);
    }

    /**
     * @param  Builder<JobListing>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', JobStatus::Active)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Jobs taking applications right now: live, unexpired and the employer is
     * hiring. What every worker-facing list shows.
     *
     * @param  Builder<JobListing>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->active()->hiring();
    }

    /**
     * Jobs whose employer is hiring: it holds a job plan, or never had one
     * (the free first post). Keeps paused jobs out of search results even
     * before `jobs:sync-hiring` has taken them out of the index.
     *
     * @param  Builder<JobListing>  $query
     */
    public function scopeHiring(Builder $query): void
    {
        $query->whereHas('employer', fn (Builder $employer) => $employer->where(fn (Builder $q) => $q
            ->whereHas('subscriptions', fn (Builder $s) => $s->entitled()->ofType(Plan::TYPE_JOB))
            ->orWhereDoesntHave('subscriptions', fn (Builder $s) => $s->ofType(Plan::TYPE_JOB)->whereNotNull('starts_at'))));
    }

    /**
     * Filter by approximate radius (km) using the haversine formula.
     *
     * @param  Builder<JobListing>  $query
     */
    public function scopeWithinRadius(Builder $query, float $lat, float $lng, float $km): void
    {
        $haversine = '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) '
            .'* cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))';

        $query->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereRaw("$haversine <= ?", [$lat, $lng, $lat, $km]);
    }
}
