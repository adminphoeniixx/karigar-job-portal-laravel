<?php

namespace App\Http\Controllers\Api\Employer;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\JobListingRequest;
use App\Http\Resources\Api\EmployerJobResource;
use App\Models\JobListing;
use App\Models\User;
use App\Notifications\NewJobNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Notification;

/**
 * Job management for the employer app — the "My Jobs" and "Post Job" screens.
 * Scoped to the employer account so team members see the same jobs as the web.
 */
class JobController extends Controller
{
    /**
     * The employer's own jobs, filtered by the Active / Closed / Drafts tabs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,active,closed'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $jobs = $request->user()->employerAccount()->jobListings()
            ->withCount([
                'applications',
                'applications as shortlisted_count' => fn ($q) => $q->whereNotNull('shortlisted_at'),
                'applications as hired_count' => fn ($q) => $q->where('status', ApplicationStatus::Accepted),
            ])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('title', 'ilike', "%{$term}%"))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(15);

        return EmployerJobResource::collection($jobs);
    }

    /**
     * A single job the employer owns, with counts.
     */
    public function show(Request $request, JobListing $job): EmployerJobResource
    {
        $this->authorize('view', $job);

        $job->loadCount([
            'applications',
            'applications as shortlisted_count' => fn ($q) => $q->whereNotNull('shortlisted_at'),
            'applications as hired_count' => fn ($q) => $q->where('status', ApplicationStatus::Accepted),
        ]);

        return new EmployerJobResource($job);
    }

    /**
     * Post a new job (or save a draft). Mirrors the web JobListingController.
     */
    public function store(JobListingRequest $request): JsonResponse
    {
        $this->authorize('create', JobListing::class);

        $account = $request->user()->employerAccount();
        $limit = $account->activeSubscription()?->plan->jobPostLimit() ?? 0;

        if ($limit > 0 && $account->jobListings()->count() >= $limit) {
            return response()->json([
                'message' => __('You have reached your plan\'s job posting limit.'),
            ], 422);
        }

        $job = $account->jobListings()->create($request->validated());

        if ($job->status === JobStatus::Active) {
            $this->notifyWorkers($job);
        }

        return response()->json([
            'message' => __('Job posted.'),
            'job' => new EmployerJobResource($job),
        ], 201);
    }

    /**
     * Update / edit a job the employer owns.
     */
    public function update(JobListingRequest $request, JobListing $job): JsonResponse
    {
        $this->authorize('update', $job);

        $wasActive = $job->status === JobStatus::Active;
        $job->update($request->validated());

        // Newly-activated job → notify workers, same as the web flow.
        if (! $wasActive && $job->status === JobStatus::Active) {
            $this->notifyWorkers($job);
        }

        return response()->json([
            'message' => __('Job updated.'),
            'job' => new EmployerJobResource($job),
        ]);
    }

    /**
     * Close a job (stop receiving applications).
     */
    public function close(Request $request, JobListing $job): JsonResponse
    {
        $this->authorize('update', $job);

        $job->update(['status' => JobStatus::Closed]);

        return response()->json([
            'message' => __('Job closed.'),
            'job' => new EmployerJobResource($job),
        ]);
    }

    /**
     * Delete a job.
     */
    public function destroy(JobListing $job): JsonResponse
    {
        $this->authorize('delete', $job);

        $job->delete();

        return response()->json(['message' => __('Job deleted.')]);
    }

    /**
     * Notify relevant workers about a newly active job. Same targeting as the
     * web JobListingController: same city or overlapping skill, else everyone.
     */
    private function notifyWorkers(JobListing $job): void
    {
        $workers = User::where('role', 'worker')
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
            })
            ->get();

        if ($workers->isEmpty()) {
            $workers = User::where('role', 'worker')->get();
        }

        if ($workers->isNotEmpty()) {
            Notification::send($workers, new NewJobNotification($job));
        }
    }
}
