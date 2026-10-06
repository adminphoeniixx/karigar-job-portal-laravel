<?php

namespace App\Support;

use App\Models\JobListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The job list the worker app opens with: jobs in the karigar's own
 * categories, nearest first from where they are right now.
 *
 * - Category: a job whose category, or one of whose skills, is one of the
 *   karigar's skills (sign-up picks them from the same craft list). A
 *   karigar with no skills sees every job, and `all` lifts the filter.
 * - Location: a place the karigar picked to look around (feed location),
 *   else the phone's position when the app sends it, else the one saved on
 *   the profile. Without either, the profile's city first, then its
 *   state, then the rest. Jobs with no map pin come after the pinned ones.
 * - Only jobs taking applications: active, and the employer hiring.
 *
 * A search or a filter the karigar types goes to {@see JobSearch} instead.
 */
final class JobFeed
{
    /** @var list<string> as the karigar wrote them */
    private array $skills;

    /** @var array{0: float, 1: float}|null */
    private ?array $point;

    /** chosen | current | profile | city | none */
    private string $locationSource;

    /** The picked place's name, when the feed is around one. */
    private ?string $locationLabel = null;

    public function __construct(private User $worker, ?float $lat = null, ?float $lng = null, bool $allCategories = false)
    {
        $profile = $worker->workerProfile;

        $this->skills = $allCategories ? [] : collect($profile?->skills ?? [])
            ->map(fn ($skill) => trim((string) $skill))
            ->filter()
            ->unique(fn ($skill) => mb_strtolower($skill))
            ->values()
            ->all();

        if ($profile?->feed_latitude !== null && $profile?->feed_longitude !== null) {
            // A place the karigar picked wins over where the phone is.
            $this->point = [(float) $profile->feed_latitude, (float) $profile->feed_longitude];
            $this->locationSource = 'chosen';
            $this->locationLabel = $profile->feed_location_label;
        } elseif ($lat !== null && $lng !== null) {
            $this->point = [$lat, $lng];
            $this->locationSource = 'current';
        } elseif ($profile?->latitude !== null && $profile?->longitude !== null) {
            $this->point = [(float) $profile->latitude, (float) $profile->longitude];
            $this->locationSource = 'profile';
        } else {
            $this->point = null;
            $this->locationSource = filled($profile?->city) || filled($profile?->state) ? 'city' : 'none';
        }
    }

    public static function for(User $worker, ?float $lat = null, ?float $lng = null, bool $allCategories = false): self
    {
        return new self($worker, $lat, $lng, $allCategories);
    }

    /**
     * The feed, ordered. With a position, every job carries `distance_km`.
     *
     * @return Builder<JobListing>
     */
    public function query(?float $radiusKm = null): Builder
    {
        $query = JobListing::query()->active()->hiring()->with('employer:id,name', 'employer.kyc');

        if ($this->skills !== []) {
            $lower = array_map(fn ($skill) => mb_strtolower($skill), $this->skills);

            $query->where(function (Builder $q) use ($lower) {
                $q->whereRaw('LOWER(category) IN ('.implode(',', array_fill(0, count($lower), '?')).')', $lower);

                // A whole skill, any case, inside the job's JSON skills list.
                foreach ($lower as $skill) {
                    $q->orWhereRaw('LOWER(CAST(skills AS TEXT)) LIKE ?', ['%'.json_encode($skill, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'%']);
                }
            });
        }

        if ($this->point !== null) {
            [$distance, $bindings] = $this->distanceSql();

            $query->select('job_listings.*')
                ->selectRaw("{$distance} as distance_km", $bindings)
                ->orderByRaw('CASE WHEN latitude IS NULL OR longitude IS NULL THEN 1 ELSE 0 END')
                ->orderBy('distance_km');

            if ($radiusKm !== null) {
                // Bound as an integer: a float goes over as text, and SQLite
                // ranks every number below any text, so nothing is ever outside.
                $query->whereRaw("{$distance} <= ?", [...$bindings, (int) ceil($radiusKm)]);
            }
        } else {
            $profile = $this->worker->workerProfile;

            $query->orderByRaw('CASE WHEN LOWER(city) = ? THEN 0 WHEN LOWER(state) = ? THEN 1 ELSE 2 END', [
                mb_strtolower((string) $profile?->city),
                mb_strtolower((string) $profile?->state),
            ]);
        }

        return $query->latest();
    }

    /**
     * What the feed was built from, for the app to say so.
     *
     * @return array{type: string, categories: list<string>, location: string, location_label: string|null}
     */
    public function meta(): array
    {
        return [
            'type' => 'for_you',
            // Empty: not filtered by category (no skills on the profile, or `all`).
            'categories' => $this->skills,
            // chosen | current | profile | city | none
            'location' => $this->locationSource,
            // The picked place's name while `location` is chosen.
            'location_label' => $this->locationLabel,
        ];
    }

    /**
     * Distance in km from the karigar to a job's pin, NULL without a pin. An
     * equirectangular approximation: plain arithmetic every database runs,
     * and within a few percent at Indian latitudes and distances.
     *
     * @return array{0: string, 1: list<float>}
     */
    private function distanceSql(): array
    {
        [$lat, $lng] = $this->point;
        $kmPerLngDegree = 111.32 * cos(deg2rad($lat));

        return [
            'CASE WHEN latitude IS NULL OR longitude IS NULL THEN NULL ELSE '
                .'SQRT(((latitude - ?) * 111.32) * ((latitude - ?) * 111.32) + ((longitude - ?) * ?) * ((longitude - ?) * ?)) END',
            [$lat, $lat, $lng, $kmPerLngDegree, $lng, $kmPerLngDegree],
        ];
    }
}
