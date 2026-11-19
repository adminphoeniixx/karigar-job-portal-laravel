<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkerProfile;
use App\Services\WorkerRejections;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Karigars matching your job", shown before a job is posted, as WorkIndia
 * shows "Candidates matching your job post: 234" with the top few: how many
 * available karigars fit the job as it stands, and up to five of them.
 *
 * A karigar fits when one of their skills is the job's category or one of
 * its skills, they live within reach of the job (NEARBY_KM for a nearby job,
 * CITY_KM otherwise, or the same city when either has no map pin), and they
 * meet the gender, education and experience asked for. What a karigar never
 * filled in does not count against them. No phone number and no full name
 * leave this class: the Worker Database is where an employer unlocks those.
 */
final class MatchingKarigars
{
    public const SHOWN = 5;

    /**
     * What the preview endpoints take: the job form's fields so far.
     *
     * @var array<string, list<string>>
     */
    public const RULES = [
        'category' => ['nullable', 'string', 'max:100'],
        'skills' => ['nullable', 'array', 'max:30'],
        'skills.*' => ['string', 'max:50'],
        'latitude' => ['nullable', 'numeric', 'between:-90,90'],
        'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        'city' => ['nullable', 'string', 'max:100'],
        'reach' => ['nullable', 'string', 'in:nearby,city'],
        'gender' => ['nullable', 'string', 'in:male,female,both'],
        'education' => ['nullable', 'string', 'max:30'],
        'experience_level' => ['nullable', 'string', 'in:any,fresher,experienced'],
        'experience_min' => ['nullable', 'integer', 'min:0', 'max:60'],
    ];

    /**
     * @param  array<string, mixed>  $job  the job form's fields so far
     * @return array{count: int, radius_km: int, karigars: list<array<string, mixed>>}
     */
    public static function preview(array $job, User $employer): array
    {
        $point = is_numeric($job['latitude'] ?? null) && is_numeric($job['longitude'] ?? null)
            ? [(float) $job['latitude'], (float) $job['longitude']]
            : null;
        $radius = ($job['reach'] ?? null) === 'nearby' ? ReferenceData::NEARBY_KM : ReferenceData::CITY_KM;

        $query = self::query($job, $employer, $point, $radius);

        if ($query === null) {
            return ['count' => 0, 'radius_km' => $radius, 'karigars' => []];
        }

        $count = (clone $query)->count();

        $top = $query->with('user:id,name', 'user.kyc')
            ->orderByDesc('experience_years')
            ->limit(50)
            ->get()
            ->map(fn (WorkerProfile $w) => self::card($w, $point))
            // Nearest first among the most experienced; no pin, last.
            ->sortBy(fn (array $card) => $card['distance_km'] ?? PHP_INT_MAX)
            ->take(self::SHOWN)
            ->values()
            ->all();

        return ['count' => $count, 'radius_km' => $radius, 'karigars' => $top];
    }

    /**
     * @param  array<string, mixed>  $job
     * @param  array{0: float, 1: float}|null  $point
     * @return Builder<WorkerProfile>|null null when the job says too little to match on
     */
    private static function query(array $job, User $employer, ?array $point, int $radius): ?Builder
    {
        $crafts = collect([$job['category'] ?? null, ...((array) ($job['skills'] ?? []))])
            ->filter(fn ($s) => is_string($s) && trim($s) !== '')
            ->map(fn (string $s) => mb_strtolower(trim($s)))
            ->unique()
            ->values();
        $city = mb_strtolower(trim((string) ($job['city'] ?? '')));

        if ($crafts->isEmpty() || ($point === null && $city === '')) {
            return null;
        }

        $query = WorkerProfile::query()
            ->where('available', true)
            ->whereHas('user', fn (Builder $q) => $q->where('role', 'worker')->whereNull('suspended_at'))
            ->whereNotIn('user_id', WorkerRejections::for($employer)->ids())
            // One of the karigar's skills, any case, is the craft.
            ->where(function (Builder $q) use ($crafts) {
                foreach ($crafts as $craft) {
                    $q->orWhereRaw('LOWER(CAST(skills AS TEXT)) LIKE ?', ['%'.json_encode($craft, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'%']);
                }
            });

        // Within reach of the pin; a karigar without one counts by city.
        $query->where(function (Builder $q) use ($point, $radius, $city) {
            if ($point !== null) {
                [$distance, $bindings] = self::distanceSql($point);
                $q->whereRaw("{$distance} <= ?", [...$bindings, $radius]);
            }

            if ($city !== '') {
                $q->orWhere(fn (Builder $q) => $q
                    ->when($point !== null, fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('latitude')->orWhereNull('longitude')))
                    ->whereRaw('LOWER(city) = ?', [$city]));
            }
        });

        $gender = $job['gender'] ?? null;
        if (in_array($gender, ['male', 'female'], true)) {
            $query->where(fn (Builder $q) => $q->where('gender', $gender)->orWhereNull('gender'));
        }

        $levels = ReferenceData::EDUCATION_LEVELS;
        $min = array_search($job['education'] ?? null, $levels, true);
        if ($min !== false && $min > 0) {
            $query->where(fn (Builder $q) => $q->whereIn('education', array_slice($levels, $min))->orWhereNull('education'));
        }

        $level = $job['experience_level'] ?? null;
        if ($level === 'fresher') {
            $query->where(fn (Builder $q) => $q->where('experience_years', 0)->orWhereNull('experience_years'));
        } elseif ($level === 'experienced' && is_numeric($job['experience_min'] ?? null) && (int) $job['experience_min'] > 0) {
            $query->where('experience_years', '>=', (int) $job['experience_min']);
        }

        return $query;
    }

    /**
     * What the preview shows of one karigar: first name and initial, never
     * a phone number.
     *
     * @param  array{0: float, 1: float}|null  $point
     * @return array<string, mixed>
     */
    private static function card(WorkerProfile $w, ?array $point): array
    {
        $parts = preg_split('/\s+/u', trim((string) $w->user?->name)) ?: [];
        $name = ($parts[0] ?? '').(isset($parts[1]) && $parts[1] !== '' ? ' '.mb_strtoupper(mb_substr($parts[1], 0, 1)).'.' : '');

        return [
            'id' => $w->id,
            'name' => $name !== '' ? $name : __('Karigar'),
            'avatar_url' => $w->avatar_url,
            'gender' => $w->gender,
            'experience_years' => $w->experience_years,
            'education' => $w->education,
            'languages' => array_values($w->spoken_languages ?? []),
            'skills' => array_slice(array_values($w->skills ?? []), 0, 5),
            'verified' => (bool) $w->user?->isKycVerified(),
            ...KarigarLocation::for($w, $point),
        ];
    }

    /**
     * Distance in km from the job's pin to a karigar's, as JobFeed measures
     * it: plain arithmetic every database runs.
     *
     * @param  array{0: float, 1: float}  $point
     * @return array{0: string, 1: list<float>}
     */
    public static function distanceSql(array $point): array
    {
        [$lat, $lng] = $point;
        $kmPerLngDegree = 111.32 * cos(deg2rad($lat));

        return [
            'CASE WHEN latitude IS NULL OR longitude IS NULL THEN NULL ELSE '
                .'SQRT(((latitude - ?) * 111.32) * ((latitude - ?) * 111.32) + ((longitude - ?) * ?) * ((longitude - ?) * ?)) END',
            [$lat, $lat, $lng, $kmPerLngDegree, $lng, $kmPerLngDegree],
        ];
    }
}
