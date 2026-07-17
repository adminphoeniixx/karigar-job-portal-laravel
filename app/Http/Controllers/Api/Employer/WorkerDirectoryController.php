<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ReviewResource;
use App\Models\WorkerProfile;
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
        ]);

        $filterBy = $this->buildFilterBy($filters);

        $search = WorkerProfile::search(trim($filters['q'] ?? '') ?: '*')
            ->query(fn ($query) => $query->with('user:id,name', 'user.kyc'));

        if ($filterBy !== '') {
            $search->options(['filter_by' => $filterBy]);
        }

        $quota = $request->user()->contactDatabaseQuota();
        $perPage = 15;
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $perPage;

        $workers = $search->paginate($perPage)->withQueryString();

        $index = 0;
        $workers->getCollection()->transform(function (WorkerProfile $w) use (&$index, $offset, $quota) {
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
                'verified' => $w->user?->kyc?->status->value === 'verified',
                'rating' => $w->user?->averageRating() ?? 0.0,
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
                'verified' => $worker->user?->kyc?->status->value === 'verified',
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
     * @param  array<string, mixed>  $filters
     */
    private function buildFilterBy(array $filters): string
    {
        $parts = [];

        foreach (['state', 'city'] as $field) {
            if (! empty($filters[$field])) {
                $parts[] = "{$field}:=`".str_replace('`', '', $filters[$field]).'`';
            }
        }

        if (! empty($filters['skill'])) {
            $parts[] = 'skills:=`'.str_replace('`', '', $filters['skill']).'`';
        }

        return implode(' && ', $parts);
    }
}
