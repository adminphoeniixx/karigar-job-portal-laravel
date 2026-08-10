<?php

namespace App\Models;

use App\Services\BunnyCdn;
use App\Services\ResumeParser;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $phone
 * @property string|null $gender
 * @property array<int, string>|null $skills
 * @property int|null $experience_years
 * @property string|null $education
 * @property array<int, string>|null $spoken_languages
 * @property string|null $bio
 * @property string|null $expected_wage
 * @property string|null $wage_type
 * @property string|null $avatar_path
 * @property string|null $city
 * @property string|null $state
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $travel_radius_km
 * @property bool $available
 */
class WorkerProfile extends Model
{
    use Searchable;

    // The resume_* columns are deliberately absent here: they are only ever
    // written as a set by App\Services\ResumeStore, never mass-assigned.
    protected $fillable = [
        'phone', 'gender', 'skills', 'experience_years', 'education', 'spoken_languages',
        'bio', 'expected_wage', 'wage_type', 'avatar_path', 'city', 'state',
        'latitude', 'longitude', 'travel_radius_km', 'available',
        'payout_upi', 'razorpayx_fund_account_id',
    ];

    /** @var list<string> */
    protected $appends = ['avatar_url'];

    // The stored resume text runs to 8k characters and the path points at a
    // private disk — neither belongs in a serialized profile. Use
    // {@see resumeSummary()} for the parts a UI may show.
    /** @var list<string> */
    protected $hidden = ['resume_path', 'resume_text'];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'spoken_languages' => 'array',
            'available' => 'boolean',
            'expected_wage' => 'decimal:2',
            'resume_uploaded_at' => 'datetime',
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
            fn (): ?string => BunnyCdn::url($this->avatar_path),
        );
    }

    /**
     * The uploaded resume as a UI can describe it — filename, when it landed,
     * and how much text the matcher actually read out of it. Null when the
     * worker has not uploaded one. Shared by the web page and the app API.
     *
     * @return array{name: string|null, uploaded_at: string|null, uploaded_ago: string|null, characters: int, max_characters: int}|null
     */
    public function resumeSummary(): ?array
    {
        if ($this->resume_path === null) {
            return null;
        }

        return [
            'name' => $this->resume_name,
            'uploaded_at' => $this->resume_uploaded_at?->toIso8601String(),
            'uploaded_ago' => $this->resume_uploaded_at?->diffForHumans(),
            // Length of the text the matcher will actually read, so the worker
            // can be reassured their resume was parsed.
            'characters' => mb_strlen((string) $this->resume_text),
            'max_characters' => ResumeParser::MAX_CHARS,
        ];
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
            // Employer "Find Workers" filters & sorts.
            'spoken_languages' => $this->spoken_languages ?? [],
            'available' => (bool) $this->available,
            'verified' => (bool) $this->user?->isKycVerified(),
            'rating' => (float) ($this->user?->averageRating() ?? 0),
            'created_at' => $this->created_at?->timestamp ?? now()->timestamp,
        ];

        if ($this->latitude !== null && $this->longitude !== null) {
            $data['location'] = [(float) $this->latitude, (float) $this->longitude];
        }

        return $data;
    }
}
