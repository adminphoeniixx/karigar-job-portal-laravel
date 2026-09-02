<?php

namespace App\Http\Controllers\Api\Employer;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\JobListingRequest;
use App\Http\Resources\Api\EmployerJobResource;
use App\Models\JobInvite;
use App\Models\JobListing;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Notifications\JobInviteNotification;
use App\Notifications\NewJobNotification;
use App\Services\CreditWallet;
use App\Services\JobDescriptionWriter;
use App\Services\JobPostingGate;
use App\Support\TemplatedMailer;
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
                'applications as interview_count' => fn ($q) => $q->whereNotNull('interview_at')
                    ->whereNotIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Rejected]),
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
            'applications as interview_count' => fn ($q) => $q->whereNotNull('interview_at')
                ->whereNotIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Rejected]),
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
        $gate = JobPostingGate::evaluate($account);

        if (! $gate['allowed']) {
            return response()->json([
                'message' => $gate['message'],
            ], 422);
        }

        $job = $account->jobListings()->create($request->validated());

        if ($gate['consumesFreePost']) {
            JobPostingGate::consumeFreePost($account);
        }

        if ($job->status === JobStatus::Active) {
            $this->notifyWorkers($job);
            $this->sendPostedEmail($job, $account);
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
            $this->sendPostedEmail($job, $request->user()->employerAccount());
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
     * Boost a job to the top of worker search, paid for with contact credits.
     */
    public function boost(Request $request, JobListing $job): JsonResponse
    {
        $this->authorize('update', $job);

        $tiers = config('billing.boost_tiers');

        $data = $request->validate([
            'tier' => ['required', 'string', 'in:'.implode(',', array_keys($tiers))],
        ]);

        $tier = $tiers[$data['tier']];
        $wallet = CreditWallet::for($request->user());

        if (! $wallet->spend((int) $tier['credits'])) {
            return response()->json([
                'message' => __('You do not have enough credits to boost this job.'),
                'code' => 'out_of_credits',
                'credits' => $wallet->summary(),
            ], 422);
        }

        // Stack on top of a running boost instead of shortening it.
        $from = $job->isBoosted() ? $job->boosted_until : now();

        $job->update([
            'boost_tier' => $data['tier'],
            'boosted_until' => $from->copy()->addDays((int) $tier['days']),
        ]);

        return response()->json([
            'message' => __('Job boosted for :days days.', ['days' => $tier['days']]),
            'job' => new EmployerJobResource($job),
            'credits' => $wallet->summary(),
        ]);
    }

    /**
     * Workers who match this job but have not applied — the "✨ Matched for
     * this job" strip on the Manage Job screen.
     */
    public function matches(Request $request, JobListing $job): JsonResponse
    {
        $this->authorize('view', $job);

        $applied = $job->applications()->pluck('worker_id');
        $invited = $job->invites()->pluck('worker_id');
        $skills = collect($job->skills ?? [])->filter()->values();

        $profiles = WorkerProfile::query()
            ->with('user:id,name', 'user.kyc')
            ->whereHas('user', fn ($q) => $q->where('role', 'worker')->whereNotIn('id', $applied))
            ->where('available', true)
            ->when($skills->isNotEmpty() || $job->category, function ($q) use ($skills, $job) {
                $q->where(function ($q) use ($skills, $job) {
                    foreach ($skills as $skill) {
                        $q->orWhereJsonContains('skills', $skill);
                    }

                    if ($job->category) {
                        $q->orWhereJsonContains('skills', $job->category);
                    }
                });
            })
            ->when($job->city, fn ($q) => $q->where('city', $job->city))
            ->when($job->experience_min, fn ($q, $min) => $q->where('experience_years', '>=', $min))
            ->orderByDesc('experience_years')
            ->limit(20)
            ->get();

        return response()->json([
            'workers' => $profiles->map(fn (WorkerProfile $w) => [
                'id' => $w->id,
                'user_id' => $w->user_id,
                'name' => $w->user?->name,
                'avatar_url' => $w->avatar_url,
                'skills' => $w->skills ?? [],
                'city' => $w->city,
                'state' => $w->state,
                'experience_years' => $w->experience_years,
                'expected_wage' => $w->expected_wage,
                'wage_type' => $w->wage_type,
                'available' => (bool) $w->available,
                'verified' => (bool) $w->user?->isKycVerified(),
                'rating' => $w->user?->averageRating() ?? 0.0,
                'invited' => $invited->contains($w->user_id),
            ])->values(),
            'total' => $profiles->count(),
        ]);
    }

    /**
     * Invite a matched worker to apply. Idempotent per job+worker.
     */
    public function invite(Request $request, JobListing $job): JsonResponse
    {
        $this->authorize('update', $job);

        $data = $request->validate([
            'worker_id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $worker = User::where('id', $data['worker_id'])->where('role', 'worker')->firstOrFail();

        $invite = JobInvite::firstOrCreate(
            ['job_listing_id' => $job->id, 'worker_id' => $worker->id],
            ['employer_id' => $job->employer_id, 'message' => $data['message'] ?? null],
        );

        if (! $invite->wasRecentlyCreated) {
            return response()->json([
                'message' => __('This worker has already been invited.'),
                'invited' => true,
            ]);
        }

        $job->loadMissing('employer:id,name');
        $worker->notify(new JobInviteNotification($job, $data['message'] ?? null));

        return response()->json([
            'message' => __('Invite sent to :name.', ['name' => $worker->name]),
            'invited' => true,
        ], 201);
    }

    /**
     * Email the employer a confirmation that their job is live. Uses the
     * admin-editable "job_posted" template; no-ops if it is missing/inactive.
     */
    /**
     * AI-drafted descriptions for the Post Job screen, so the employer is not
     * staring at a blank box. Mirrors the web jobs.suggestDescription route;
     * with no AI key configured the writer falls back to a template draft.
     */
    public function suggestDescription(Request $request, JobDescriptionWriter $writer): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'category' => ['nullable', 'string', 'max:80'],
            'skills' => ['nullable', 'array', 'max:20'],
            'skills.*' => ['string', 'max:60'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json([
            'suggestions' => $writer->suggest(
                $data['title'],
                $data['category'] ?? null,
                array_values($data['skills'] ?? []),
                $data['city'] ?? null,
                $data['state'] ?? null,
            ),
        ]);
    }

    private function sendPostedEmail(JobListing $job, User $account): void
    {
        TemplatedMailer::send('job_posted', $account->email, [
            'employer_name' => $account->name,
            'job_title' => $job->title,
            'job_location' => trim(implode(', ', array_filter([$job->city, $job->state]))) ?: '—',
            'action_url' => url("/employer/jobs/{$job->id}/applicants"),
        ]);
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
