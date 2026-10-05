<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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
 * @property string|null $contact_name
 * @property string|null $contact_designation
 * @property int|null $reposted_from_id
 * @property string|null $city
 * @property string|null $state
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
        'address', 'city', 'state', 'latitude', 'longitude',
        'vacancies', 'experience_min', 'experience_max', 'status', 'published_at', 'expires_at',
        'contact_mode', 'contact_phone', 'contact_name', 'contact_designation',
        'shift', 'shift_start', 'shift_end', 'perks', 'reposted_from_id',
        'requires_worker_fee', 'worker_fee_amount',
        'boost_tier', 'boosted_until',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'perks' => 'array',
            'experience_min' => 'integer',
            'experience_max' => 'integer',
            'views_count' => 'integer',
            'boosted_until' => 'datetime',
            'requires_worker_fee' => 'boolean',
            'worker_fee_amount' => 'decimal:2',
            'status' => JobStatus::class,
            'expires_at' => 'datetime',
            'published_at' => 'datetime',
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
        });
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
            $min !== null && $max !== null && $min === $max => trans_choice(':count yr|:count yrs', $min, ['count' => $min]),
            $min !== null && $max !== null => __(':min–:max yrs', ['min' => $min, 'max' => $max]),
            $min === 0 => __('Freshers welcome'),
            $min !== null => __(':min+ yrs', ['min' => $min]),
            $max !== null => __('Up to :max yrs', ['max' => $max]),
            default => null,
        };
    }

    /**
     * The shift's hours, "9:00 AM – 6:00 PM", or null without them.
     */
    public function shiftHoursLabel(): ?string
    {
        if (! $this->shift_start || ! $this->shift_end) {
            return null;
        }

        $format = fn (string $time): string => Carbon::createFromFormat('H:i', $time)->format('g:i A');

        return $format($this->shift_start).' – '.$format($this->shift_end);
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
