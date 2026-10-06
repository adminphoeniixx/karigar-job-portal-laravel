<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ReviewController;
use App\Models\WorkerProfile;
use App\Services\ContactList;
use App\Services\ContactUnlocks;
use Illuminate\Http\RedirectResponse;
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

        // The plan (+ admin bonus) decides how far down the results the
        // employer may reach. Numbers stay hidden until the karigar is
        // unlocked, which spends from the same pool as applicant unlocks.
        $quota = $request->user()->contactDatabaseQuota();
        $wallet = ContactUnlocks::for($request->user());
        $unlockedIds = array_flip($wallet->unlockedWorkerIds());
        $perPage = 15;
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $perPage;

        $workers = $search->paginate($perPage)->withQueryString();

        $index = 0;
        $workers->getCollection()->transform(function (WorkerProfile $w) use (&$index, $offset, $quota, $unlockedIds) {
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
                'rating' => $w->user?->averageRating() ?? 0.0,
                'phone' => $unlocked ? $w->phone : null,
                'email' => $unlocked ? $w->user?->email : null,
                'locked' => ! $unlocked,
                'can_unlock' => ! $paidFor && $inQuota,
            ];
        });

        return Inertia::render('workers/Index', [
            'workers' => $workers,
            'filters' => $filters,
            'access' => [
                'quota' => $quota,
                'accessible' => min($workers->total(), $quota),
                'total' => $workers->total(),
                // A job or database plan that opens the Worker Database.
                'has_plan' => $quota > 0,
            ],
            'unlocks' => $this->unlocks($wallet),
            'contactCounts' => ContactList::for($request->user())->counts(),
        ]);
    }

    public function show(Request $request, WorkerProfile $worker): Response
    {
        $worker->load('user:id,name,email');

        $wallet = ContactUnlocks::for($request->user());
        $unlocked = $worker->user !== null && $wallet->contactVisible($worker->user_id);

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
                'can_unlock' => $worker->user !== null && ! $wallet->hasUnlocked($worker->user_id) && $request->user()->contactDatabaseQuota() > 0,
            ],
            'unlocks' => $this->unlocks($wallet),
            'reviews' => $worker->user ? ReviewController::summaryFor($worker->user) : null,
        ]);
    }

    /**
     * Reveal a karigar's number from the Worker Database, spending one contact
     * unlock (the same pool applicant unlocks use). Already unlocked is free.
     */
    public function unlock(Request $request, WorkerProfile $worker): RedirectResponse
    {
        if ($worker->user === null) {
            abort(404);
        }

        if ($request->user()->contactDatabaseQuota() <= 0) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => __('Subscribe to a plan to unlock karigar contacts.'),
            ]);
        }

        if (! ContactUnlocks::for($request->user())->unlockWorker($worker->user, $request->user())) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => __('You have reached your plan\'s contact unlock limit.'),
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Contact unlocked.'),
        ]);
    }

    /**
     * The unlock counter the directory pages show.
     *
     * @return array{used: int, limit: int, remaining: int|null}
     */
    private function unlocks(ContactUnlocks $wallet): array
    {
        return [
            'used' => $wallet->unlocksUsed(),
            'limit' => $wallet->planLimit(),
            'remaining' => $wallet->planRemaining(),
        ];
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
