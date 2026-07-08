<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

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
 * @property string|null $city
 * @property string|null $state
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int $vacancies
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
        'city', 'state', 'latitude', 'longitude',
        'vacancies', 'status', 'expires_at',
        'contact_mode', 'contact_phone', 'shift', 'perks',
        'requires_worker_fee', 'worker_fee_amount',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'perks' => 'array',
            'requires_worker_fee' => 'boolean',
            'worker_fee_amount' => 'decimal:2',
            'status' => JobStatus::class,
            'expires_at' => 'datetime',
            'wage_min' => 'decimal:2',
            'wage_max' => 'decimal:2',
        ];
    }

    public function searchableAs(): string
    {
        return 'job_listings';
    }

    /**
     * Only active, unexpired jobs are indexed for public search.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->status === JobStatus::Active
            && ($this->expires_at === null || $this->expires_at->isFuture());
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
