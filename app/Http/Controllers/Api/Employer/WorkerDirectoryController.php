<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ReviewResource;
use App\Models\WorkerProfile;
use App\Support\ReferenceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employer-facing "Find Workers" directory, powered by Typesense — the same
 * search + plan-quota logic as the web Employer\WorkerDirectoryController.
 */
class WorkerDirectoryController extends Controller
{
    /**
     * Search / browse workers. Rows beyond the plan quota are returned locked
     * (no contact details).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'skill' => ['nullable', 'string', 'max:50'],
            // Filter sheet.
            'experience_min' => ['nullable', 'integer', 'min:0', 'max:60'],
            'wage_min' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'wage_max' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'gte:wage_min'],
            'languages' => ['nullable', 'array', 'max:10'],
            'languages.*' => ['string', 'max:40'],
            'verified' => ['nullable', 'boolean'],
            'available' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', ReferenceData::WORKER_SORTS)],
            // Distance filter/sort — needs the employer's (or site's) position.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:500'],
        ]);

        $options = array_filter([
            'filter_by' => $this->buildFilterBy($filters),
            'sort_by' => $this->buildSortBy($filters),
        ], fn ($v) => $v !== '');

        $search = WorkerProfile::search(trim($filters['q'] ?? '') ?: '*')
            ->query(fn ($query) => $query->with('user:id,name', 'user.kyc'));

        if ($options !== []) {
            $search->options($options);
        }

        $quota = $request->user()->contactDatabaseQuota();
        $perPage = 15;
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $perPage;

        $workers = $search->paginate($perPage)->withQueryString();

        $point = isset($filters['latitude'], $filters['longitude'])
            ? [(float) $filters['latitude'], (float) $filters['longitude']]
            : null;

        $index = 0;
        $workers->getCollection()->transform(function (WorkerProfile $w) use (&$index, $offset, $quota, $point) {
            $unlocked = $quota > 0 && ($offset + $index) < $quota;
            $index++;

            return [
                'id' => $w->id,
                'user_id' => $w->user_id,
                'name' => $w->user?->name,
                'avatar_url' => $w->avatar_url,
                'bio' => $w->bio,
                'skills' => $w->skills ?? [],
                'city' => $w->city,
                'state' => $w->state,
                'experience_years' => $w->experience_years,
                'expected_wage' => $w->expected_wage,
                'wage_type' => $w->wage_type,
                'available' => (bool) $w->available,
                'verified' => (bool) $w->user?->isKycVerified(),
                'rating' => $w->user?->averageRating() ?? 0.0,
                'distance_km' => $point ? $this->distanceKm($point, $w) : null,
                'phone' => $unlocked ? $w->phone : null,
                'locked' => ! $unlocked,
            ];
        });

        return response()->json([
            'workers' => $workers,
            'filters' => $filters,
            'access' => [
                'quota' => $quota,
                'accessible' => min($workers->total(), $quota),
                'total' => $workers->total(),
                'has_plan' => $request->user()->hasActiveSubscription(),
            ],
        ]);
    }

    /**
     * A single worker's public profile. Contact is revealed only when this
     * employer has unlocked the worker through any application.
     */
    public function show(Request $request, WorkerProfile $worker): JsonResponse
    {
        $worker->load('user:id,name,email,phone', 'user.kyc');

        $unlocked = $worker->user
            ? $worker->user->applications()
                ->where('contact_unlocked', true)
                ->whereHas('job', fn ($q) => $q->where('employer_id', $request->user()->employerAccount()->id))
                ->exists()
            : false;

        $reviews = $worker->user
            ? $worker->user->reviewsReceived()->with('reviewer:id,name', 'job:id,title')->latest()->limit(10)->get()
            : collect();

        return response()->json([
            'worker' => [
                'id' => $worker->id,
                'user_id' => $worker->user_id,
                'name' => $worker->user?->name,
                'avatar_url' => $worker->avatar_url,
                'bio' => $worker->bio,
                'skills' => $worker->skills ?? [],
                'spoken_languages' => $worker->spoken_languages ?? [],
                'city' => $worker->city,
                'state' => $worker->state,
                'experience_years' => $worker->experience_years,
                'education' => $worker->education,
                'expected_wage' => $worker->expected_wage,
                'wage_type' => $worker->wage_type,
                'available' => (bool) $worker->available,
                'verified' => (bool) $worker->user?->isKycVerified(),
                'phone' => $unlocked ? ($worker->phone ?? $worker->user?->phone) : null,
                'email' => $unlocked ? $worker->user?->email : null,
                'contact_unlocked' => $unlocked,
            ],
            'rating' => [
                'average' => $worker->user?->averageRating() ?? 0.0,
                'count' => $worker->user ? $worker->user->reviewsReceived()->count() : 0,
            ],
            'reviews' => ReviewResource::collection($reviews),
        ]);
    }

    /**
     * Typesense `filter_by` for the Find Workers filter sheet.
     *
     * @param  array<string, mixed>  $filters
     */
    private function buildFilterBy(array $filters): string
    {
        $parts = [];

        foreach (['state', 'city'] as $field) {
            if (! empty($filters[$field])) {
                $parts[] = "{$field}:=".$this->quote($filters[$field]);
            }
        }

        if (! empty($filters['skill'])) {
            $parts[] = 'skills:='.$this->quote($filters['skill']);
        }

        if (! empty($filters['languages'])) {
            $langs = collect($filters['languages'])->map(fn ($l) => $this->quote($l))->join(',');
            $parts[] = "spoken_languages:=[{$langs}]";
        }

        if (isset($filters['experience_min'])) {
            $parts[] = 'experience_years:>='.(int) $filters['experience_min'];
        }

        if (isset($filters['wage_min'])) {
            $parts[] = 'expected_wage:>='.(float) $filters['wage_min'];
        }

        if (isset($filters['wage_max'])) {
            $parts[] = 'expected_wage:<='.(float) $filters['wage_max'];
        }

        if (! empty($filters['verified'])) {
            $parts[] = 'verified:=true';
        }

        if (! empty($filters['available'])) {
            $parts[] = 'available:=true';
        }

        // "Distance from site" — geo radius around the given point.
        if (isset($filters['latitude'], $filters['longitude']) && ! empty($filters['radius_km'])) {
            $parts[] = sprintf(
                'location:(%F, %F, %F km)',
                (float) $filters['latitude'],
                (float) $filters['longitude'],
                (float) $filters['radius_km'],
            );
        }

        return implode(' && ', $parts);
    }

    /**
     * Typesense `sort_by` for the filter sheet's "Sort by" dropdown. Best match
     * falls back to plain text relevance (no explicit sort).
     *
     * @param  array<string, mixed>  $filters
     */
    private function buildSortBy(array $filters): string
    {
        $hasPoint = isset($filters['latitude'], $filters['longitude']);

        return match ($filters['sort'] ?? 'best_match') {
            'nearest' => $hasPoint
                ? sprintf('location(%F, %F):asc', (float) $filters['latitude'], (float) $filters['longitude'])
                : '',
            'rating' => 'rating:desc',
            'experience' => 'experience_years:desc',
            'wage_low' => 'expected_wage:asc',
            default => '',
        };
    }

    /**
     * Backtick-quote a Typesense filter value.
     */
    private function quote(string $value): string
    {
        return '`'.str_replace('`', '', $value).'`';
    }

    /**
     * Straight-line distance (km, 1 decimal) from the searched point to the
     * worker, for the "3.2 km" line on the worker card. Null when unknown.
     *
     * @param  array{0: float, 1: float}  $point
     */
    private function distanceKm(array $point, WorkerProfile $worker): ?float
    {
        if ($worker->latitude === null || $worker->longitude === null) {
            return null;
        }

        [$lat1, $lng1] = $point;
        $lat2 = (float) $worker->latitude;
        $lng2 = (float) $worker->longitude;

        $km = 6371 * acos(min(1.0, cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * cos(deg2rad($lng2) - deg2rad($lng1))
            + sin(deg2rad($lat1)) * sin(deg2rad($lat2))));

        return round($km, 1);
    }
}
