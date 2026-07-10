<?php

namespace App\Http\Controllers;

use App\Enums\JobStatus;
use App\Http\Requests\JobListingRequest;
use App\Models\JobListing;
use App\Models\User;
use App\Notifications\NewJobNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class JobListingController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:draft,active,closed,expired'],
        ]);

        $jobs = $request->user()->employerAccount()->jobListings()
            ->withCount('applications')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('title', 'ilike', "%{$term}%"))
            ->when($filters['status'] ?? null, function ($q, $status) {
                if ($status === 'expired') {
                    return $q->whereNotNull('expires_at')->where('expires_at', '<=', now());
                }

                return $q->where('status', $status)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('jobs/Index', [
            'jobs' => $jobs,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', JobListing::class);

        return Inertia::render('jobs/Form', [
            'job' => null,
            'defaultPhone' => $request->user()->employerProfile?->phone,
        ]);
    }

    public function store(JobListingRequest $request): RedirectResponse
    {
        $this->authorize('create', JobListing::class);

        $user = $request->user()->employerAccount();
        $limit = $user->activeSubscription()?->plan->jobPostLimit() ?? 0;

        if ($limit > 0 && $user->jobListings()->count() >= $limit) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => __('You have reached your plan\'s job posting limit.'),
            ]);
        }

        $job = $user->jobListings()->create($request->validated());

        // Notify workers about the new opening (active jobs only).
        if ($job->status === JobStatus::Active) {
            $this->notifyWorkers($job);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Job posted.')]);

        return to_route('jobs.index');
    }

    /**
     * Notify relevant workers about a newly posted job. Targets workers in the
     * same city or with an overlapping skill; falls back to all workers.
     */
    private function notifyWorkers(JobListing $job): void
    {
        $query = User::where('role', 'worker')
            ->whereHas('workerProfile', function ($q) use ($job) {
                $q->where('available', true)
                    ->where(function ($q) use ($job) {
                        if ($job->city) {
                            $q->orWhere('city', $job->city);
                        }
                        foreach ($job->skills ?? [] as $skill) {
                            $q->orWhereJsonContains('skills', $skill);
                        }
                    });
            });

        $workers = (clone $query)->get();

        // If nobody matched on location/skill, fall back to all workers.
        if ($workers->isEmpty()) {
            $workers = User::where('role', 'worker')->get();
        }

        if ($workers->isNotEmpty()) {
            Notification::send($workers, new NewJobNotification($job));
        }
    }

    public function edit(Request $request, JobListing $job): Response
    {
        $this->authorize('update', $job);

        return Inertia::render('jobs/Form', [
            'job' => $job,
            'defaultPhone' => $request->user()->employerProfile?->phone,
        ]);
    }

    public function update(JobListingRequest $request, JobListing $job): RedirectResponse
    {
        $this->authorize('update', $job);

        $job->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Job updated.')]);

        return to_route('jobs.index');
    }

    public function destroy(JobListing $job): RedirectResponse
    {
        $this->authorize('delete', $job);

        $job->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Job deleted.')]);

        return to_route('jobs.index');
    }
}
