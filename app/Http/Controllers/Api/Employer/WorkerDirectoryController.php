<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ReviewResource;
use App\Models\WorkerProfile;
use App\Services\ContactList;
use App\Services\ContactUnlocks;
use App\Services\WorkerRejections;
use App\Support\KarigarLocation;
use App\Support\ReferenceData;
use App\Support\Verification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Employer-facing "Find Workers" directory, powered by Typesense — the same
 * search + plan-quota logic as the web Employer\WorkerDirectoryController.
 */
class WorkerDirectoryController extends Controller
{
    /**
     * Search / browse workers. Numbers come back only for karigars this
     * employer has unlocked; `can_unlock` marks the rows within the plan quota
     * that {@see unlock()} may reveal.
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
            // The "Rejected" tab: only the karigars this employer turned down.
            'rejected' => ['nullable', 'boolean'],
        ]);

        // "Nearest" and the radius filter measure from the employer's own
        // location when the app sent no point.
        $point = KarigarLocation::employerOrigin(
            $request->user(),
            isset($filters['latitude'], $filters['longitude']) ? [(float) $filters['latitude'], (float) $filters['longitude']] : null,
        );
        $search = $point === null ? $filters : ['latitude' => $point[0], 'longitude' => $point[1]] + $filters;

        // Rejected karigars leave the list (so the next ones move up into the
        // plan's reach) and wait under the "Rejected" tab.
        $rejections = WorkerRejections::for($request->user());
        $onlyRejected = (bool) ($filters['rejected'] ?? false);

        $options = array_filter([
            'filter_by' => collect([$this->buildFilterBy($search), $rejections->searchFilter($onlyRejected)])->filter()->join(' && '),
            'sort_by' => $this->buildSortBy($search),
        ], fn ($v) => $v !== '');

        $search = WorkerProfile::search(trim($filters['q'] ?? '') ?: '*')
            ->query(fn ($query) => $query->with('user:id,name', 'user.kyc'));

        if ($options !== []) {
            $search->options($options);
        }

        $quota = $request->user()->contactDatabaseQuota();
        $wallet = ContactUnlocks::for($request->user());
        $unlockedIds = array_flip($wallet->unlockedWorkerIds());
        $perPage = 15;
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $perPage;

        $workers = $onlyRejected && $rejections->count() === 0
            ? new LengthAwarePaginator([], 0, $perPage, $page, ['path' => $request->url()])
            : $search->paginate($perPage)->withQueryString();

        $othersUnlocked = $wallet->othersUnlockCounts($workers->getCollection()->pluck('user_id')->all());

        $index = 0;
        $workers->getCollection()->transform(function (WorkerProfile $w) use (&$index, $offset, $quota, $point, $unlockedIds, $othersUnlocked, $rejections) {
            $inQuota = $quota > 0 && ($offset + $index) < $quota;
            // A number shows while the database is open to the employer.
            $paidFor = isset($unlockedIds[$w->user_id]);
            $unlocked = $paidFor && $quota > 0;
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
                // "1.5 km · Sanganer, Jaipur": distance_km, distance_label
                // ("~4 km" while only the city is known), area, location_label.
                ...KarigarLocation::for($w, $point),
                // Other employers who unlocked this karigar's number.
                'unlocked_by_count' => $othersUnlocked[$w->user_id] ?? 0,
                'rejected' => $rejections->has($w->user_id),
                'phone' => $unlocked ? $w->phone : null,
                'locked' => ! $unlocked,
                'contact_unlocked' => $unlocked,
                'can_unlock' => ! $paidFor && $inQuota,
            ];
        });

        return response()->json([
            'workers' => $workers,
            'filters' => $filters,
            'access' => [
                'quota' => $quota,
                'accessible' => min($workers->total(), $quota),
                'total' => $workers->total(),
                // A job or database plan that opens the Worker Database.
                'has_plan' => $quota > 0,
            ],
            'unlocks' => $wallet->summary(),
            'database' => $wallet->database(),
            'contact_counts' => ContactList::for($request->user())->counts(),
            // For the "Rejected (3)" tab.
            'rejected_count' => $rejections->count(),
        ]);
    }

    /**
     * A single worker's public profile. Contact is revealed while this
     * employer may see it: unlocked, and a plan still showing it
     * ({@see ContactUnlocks::contactVisible()}).
     */
    public function show(Request $request, WorkerProfile $worker): JsonResponse
    {
        $worker->load('user:id,name,email,phone', 'user.kyc');

        $wallet = ContactUnlocks::for($request->user());
        $unlocked = $worker->user !== null && $wallet->contactVisible($worker->user_id);
        $point = KarigarLocation::employerOrigin($request->user());

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
                ...KarigarLocation::for($worker, $point),
                'unlocked_by_count' => $wallet->othersUnlockCounts([$worker->user_id])[$worker->user_id] ?? 0,
                'rejected' => WorkerRejections::for($request->user())->has($worker->user_id),
                'phone' => $unlocked ? ($worker->phone ?? $worker->user?->phone) : null,
                'email' => $unlocked ? $worker->user?->contactEmail() : null,
                'contact_unlocked' => $unlocked,
                'can_unlock' => $worker->user !== null && ! $wallet->hasUnlocked($worker->user_id) && $request->user()->contactDatabaseQuota() > 0,
            ],
            'rating' => [
                'average' => $worker->user?->averageRating() ?? 0.0,
                'count' => $worker->user ? $worker->user->reviewsReceived()->count() : 0,
            ],
            'reviews' => ReviewResource::collection($reviews),
        ]);
    }

    /**
     * Reveal a karigar's number from the directory, spending one contact
     * unlock (the same pool applicant unlocks use). Already unlocked is free.
     */
    public function unlock(Request $request, WorkerProfile $worker): JsonResponse
    {
        $worker->load('user:id,name,email,phone');

        if ($worker->user === null) {
            abort(404);
        }

        if ($request->user()->contactDatabaseQuota() <= 0) {
            return response()->json([
                'message' => __('Subscribe to a plan to unlock karigar contacts.'),
                'code' => 'no_plan',
            ], 422);
        }

        $wallet = ContactUnlocks::for($request->user());

        if (! $wallet->unlockWorker($worker->user, $request->user())) {
            return response()->json([
                'message' => __('You have reached your plan\'s contact unlock limit.'),
                'code' => 'unlock_limit_reached',
                'unlocks' => $wallet->summary(),
            ], 422);
        }

        return response()->json([
            'message' => __('Contact unlocked.'),
            'worker' => [
                'id' => $worker->id,
                'user_id' => $worker->user_id,
                'phone' => $worker->phone ?? $worker->user->phone,
                'email' => $worker->user->contactEmail(),
                'contact_unlocked' => true,
            ],
            'unlocks' => ContactUnlocks::for($request->user())->summary(),
        ]);
    }

    /**
     * Turn a karigar down without unlocking their number: free, nothing is
     * spent. They leave this employer's searches and job matches and wait
     * under the "Rejected" tab ({@see index()} with `rejected=1`).
     */
    public function reject(Request $request, WorkerProfile $worker): JsonResponse
    {
        abort_if($worker->user === null, 404);

        $rejections = WorkerRejections::for($request->user());
        $rejections->reject($worker->user, $request->user());

        return response()->json([
            'message' => __('Karigar rejected. They won\'t show in your search any more.'),
            'worker' => ['id' => $worker->id, 'user_id' => $worker->user_id, 'rejected' => true],
            'rejected_count' => $rejections->count(),
        ]);
    }

    /**
     * Take a rejected karigar back into the searches.
     */
    public function restore(Request $request, WorkerProfile $worker): JsonResponse
    {
        abort_if($worker->user === null, 404);

        $rejections = WorkerRejections::for($request->user());
        $rejections->restore($worker->user);

        return response()->json([
            'message' => __('Karigar is back in your search.'),
            'worker' => ['id' => $worker->id, 'user_id' => $worker->user_id, 'rejected' => false],
            'rejected_count' => $rejections->count(),
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

        // The index keeps the badge as it was when the karigar was last synced,
        // so with karigar verification off the filter would still narrow the
        // list by a badge nobody sees: skip it.
        if (! empty($filters['verified']) && Verification::forWorkers()) {
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
}
