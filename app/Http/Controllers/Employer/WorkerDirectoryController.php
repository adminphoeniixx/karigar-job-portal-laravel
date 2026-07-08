<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ReviewController;
use App\Models\WorkerProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkerDirectoryController extends Controller
{
    /**
     * Employer-facing worker directory, powered by Typesense.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'skill' => ['nullable', 'string', 'max:50'],
        ]);

        $filterBy = $this->buildFilterBy($filters);

        $search = WorkerProfile::search(trim($filters['q'] ?? '') ?: '*')
            ->query(fn ($query) => $query->with('user:id,name,email'));

        if ($filterBy !== '') {
            $search->options(['filter_by' => $filterBy]);
        }

        // The employer's plan (+ admin bonus) decides how many contacts they can
        // access. Rows beyond that quota are shown locked, without contact details.
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
                'rating' => $w->user?->averageRating() ?? 0.0,
                // Contact revealed directly for rows within the plan quota.
                'phone' => $unlocked ? $w->phone : null,
                'email' => $unlocked ? $w->user?->email : null,
                'locked' => ! $unlocked,
            ];
        });

        return Inertia::render('workers/Index', [
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

    public function show(WorkerProfile $worker): Response
    {
        $worker->load('user:id,name,email');

        // Has this employer unlocked this worker's contact via any application?
        $unlocked = $worker->user
            ? $worker->user->applications()
                ->where('contact_unlocked', true)
                ->whereHas('job', fn ($q) => $q->where('employer_id', request()->user()->id))
                ->exists()
            : false;

        return Inertia::render('workers/Show', [
            'worker' => [
                'id' => $worker->id,
                'user_id' => $worker->user_id,
                'name' => $worker->user?->name,
                'avatar_url' => $worker->avatar_url,
                'bio' => $worker->bio,
                'skills' => $worker->skills ?? [],
                'city' => $worker->city,
                'state' => $worker->state,
                'experience_years' => $worker->experience_years,
                'expected_wage' => $worker->expected_wage,
                'wage_type' => $worker->wage_type,
                'available' => $worker->available,
                'phone' => $unlocked ? $worker->phone : null,
                'email' => $unlocked ? $worker->user?->email : null,
                'contact_unlocked' => $unlocked,
            ],
            'reviews' => $worker->user ? ReviewController::summaryFor($worker->user) : null,
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
