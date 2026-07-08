<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $phone
 * @property array<int, string>|null $skills
 * @property int|null $experience_years
 * @property string|null $bio
 * @property string|null $expected_wage
 * @property string|null $wage_type
 * @property string|null $avatar_path
 * @property string|null $city
 * @property string|null $state
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $available
 */
class WorkerProfile extends Model
{
    use Searchable;

    protected $fillable = [
        'phone', 'skills', 'experience_years', 'bio', 'expected_wage',
        'wage_type', 'avatar_path', 'city', 'state', 'latitude', 'longitude', 'available',
        'payout_upi', 'razorpayx_fund_account_id',
    ];

    /** @var list<string> */
    protected $appends = ['avatar_url'];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'available' => 'boolean',
            'expected_wage' => 'decimal:2',
        ];
    }

    /**
     * Public URL for the uploaded avatar, or null when none is set.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->avatar_path ? asset('storage/'.$this->avatar_path) : null,
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function searchableAs(): string
    {
        return 'worker_profiles';
    }

    /**
     * Only available workers who have filled in at least their skills are
     * exposed in the employer-facing directory.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->available && ! empty($this->skills);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $data = [
            'id' => (string) $this->id,
            'user_id' => (string) $this->user_id,
            'name' => (string) ($this->user?->name ?? ''),
            'bio' => (string) ($this->bio ?? ''),
            'skills' => $this->skills ?? [],
            'city' => $this->city,
            'state' => $this->state,
            'experience_years' => (int) ($this->experience_years ?? 0),
            'expected_wage' => $this->expected_wage !== null ? (float) $this->expected_wage : null,
            'created_at' => $this->created_at?->timestamp ?? now()->timestamp,
        ];

        if ($this->latitude !== null && $this->longitude !== null) {
            $data['location'] = [(float) $this->latitude, (float) $this->longitude];
        }

        return $data;
    }
}
