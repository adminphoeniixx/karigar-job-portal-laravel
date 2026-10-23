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
use App\Services\AiCredits;
use App\Services\ContactUnlocks;
use App\Services\JobDescriptionWriter;
use App\Services\JobPostingGate;
use App\Services\JobRepost;
use App\Services\WorkerRejections;
use App\Support\EmployerVerification;
use App\Support\JobAnnouncer;
use App\Support\JobFormOptions;
use App\Support\JobModeration;
use App\Support\KarigarLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

        return EmployerJobResource::collection($jobs)->additional([
            // The job plan ran out: live jobs are out of search and closed to
            // applications until it is renewed.
            'hiring_paused' => $request->user()->employerAccount()->jobPlanLapsed(),
        ]);
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

        // Only a job going live spends the quota; a draft is always saved.
        $gate = $request->input('status') === JobStatus::Active->value
            ? JobPostingGate::evaluate($account)
            : null;

        if ($gate !== null && ! $gate['allowed']) {
            return response()->json([
                'message' => $gate['message'],
                'code' => $gate['code'],
            ], 422);
        }

        $data = $request->validated();

        // AI calls are paid for with credits: switching them on needs some.
        if ($creditBlock = AiCredits::jobGate($account, $data)) {
            return response()->json([
                'message' => $creditBlock,
                'code' => 'ai_credits_required',
                'ai_credits' => AiCredits::for($account)->summary(),
            ], 422);
        }

        // Safety check, on the queue: the job waits as a draft until it
        // passes, and only then goes live, spends the free post and alerts
        // karigars (JobModeration).
        $check = JobModeration::needsCheck($data);

        if ($check) {
            JobModeration::begin($data);
        }

        $job = $account->jobListings()->create($data);

        if ($check) {
            JobModeration::queue($job);
        } elseif ($job->status === JobStatus::Active) {
            if ($gate !== null && $gate['consumesFreePost']) {
                JobPostingGate::consumeFreePost($account);
            }

            JobAnnouncer::announce($job);
        }

        $job = $job->fresh();
        $outcome = $check ? JobModeration::outcome($job) : ['message' => null, 'code' => null];

        return response()->json([
            'message' => $outcome['message'] ?? ($job->isDraft() ? __('Draft saved.') : __('Job posted.')),
            // job_checking: the safety check is running, the job goes live
            // (or is held) in a minute and a push says which.
            // job_under_review: the check held it; it is not live.
            'code' => $outcome['code'],
            'job' => new EmployerJobResource($job),
        ], 201);
    }

    /**
     * Update / edit a job the employer owns.
     */
    public function update(JobListingRequest $request, JobListing $job): JsonResponse
    {
        $this->authorize('update', $job);

        // Its check decides in a minute; an edit now would race it.
        if (JobModeration::isChecking($job)) {
            return response()->json(['message' => JobModeration::busyMessage(), 'code' => 'job_checking'], 409);
        }

        $account = $request->user()->employerAccount();

        // Publishing a draft is checked against the plan like a new post.
        $goingLive = $job->published_at === null && $request->input('status') === JobStatus::Active->value;
        $gate = $goingLive ? JobPostingGate::evaluate($account) : null;

        if ($gate !== null && ! $gate['allowed']) {
            return response()->json(['message' => $gate['message'], 'code' => $gate['code']], 422);
        }

        $wasActive = $job->status === JobStatus::Active;

        $data = $request->validated();

        if ($creditBlock = AiCredits::jobGate($account, $data, $job)) {
            return response()->json([
                'message' => $creditBlock,
                'code' => 'ai_credits_required',
                'ai_credits' => AiCredits::for($account)->summary(),
            ], 422);
        }

        // Safety check when it goes live, or a live job's words change: off
        // the site until the queued check passes it.
        $check = JobModeration::needsCheck($data, $job);

        if ($check) {
            JobModeration::begin($data);
        }

        $job->update($data);

        if ($check) {
            JobModeration::queue($job);
        } else {
            if ($gate !== null && $gate['consumesFreePost']) {
                JobPostingGate::consumeFreePost($account);
            }

            // Newly-activated job → notify workers, same as the web flow.
            if (! $wasActive && $job->status === JobStatus::Active) {
                JobAnnouncer::announce($job);
            }
        }

        $job = $job->fresh();
        $outcome = $check ? JobModeration::outcome($job) : ['message' => null, 'code' => null];

        return response()->json([
            'message' => $outcome['message'] ?? match (true) {
                $goingLive => __('Job posted.'),
                $job->isDraft() => __('Draft saved.'),
                default => __('Job updated.'),
            },
            'code' => $outcome['code'],
            'job' => new EmployerJobResource($job),
        ]);
    }

    /**
     * Close a job (stop receiving applications).
     */
    public function close(Request $request, JobListing $job): JsonResponse
    {
        $this->authorize('update', $job);

        if (JobModeration::isChecking($job)) {
            return response()->json(['message' => JobModeration::busyMessage(), 'code' => 'job_checking'], 409);
        }

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
     * Workers who match this job but have not applied — the "✨ Matched for
     * this job" strip on the Manage Job screen.
     */
    public function matches(Request $request, JobListing $job): JsonResponse
    {
        $this->authorize('view', $job);

        $applied = $job->applications()->pluck('worker_id');
        $invited = $job->invites()->pluck('worker_id');
        $skills = collect($job->skills ?? [])->filter()->values();
        // Karigars this employer turned down in the Worker Database.
        $rejected = WorkerRejections::for($request->user())->ids();

        $profiles = WorkerProfile::query()
            ->with('user:id,name', 'user.kyc')
            ->whereHas('user', fn ($q) => $q->where('role', 'worker')->whereNotIn('id', $applied))
            ->whereNotIn('user_id', $rejected)
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

        $origin = KarigarLocation::jobOrigin($job, $request->user());
        $othersUnlocked = ContactUnlocks::for($request->user())->othersUnlockCounts($profiles->pluck('user_id')->all());

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
                // Measured from the job's site.
                ...KarigarLocation::for($w, $origin),
                'unlocked_by_count' => $othersUnlocked[$w->user_id] ?? 0,
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

        if ($worker->isUnavailableWorker()) {
            return response()->json([
                'message' => __('This karigar is not available for work right now.'),
                'code' => 'worker_unavailable',
            ], 422);
        }

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
            // en | hi (Devanagari)
            'language' => ['nullable', 'string', 'in:'.implode(',', JobDescriptionWriter::LANGUAGES)],
        ]);

        return response()->json([
            'suggestions' => $writer->suggest(
                $data['title'],
                $data['category'] ?? null,
                array_values($data['skills'] ?? []),
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['language'] ?? 'en',
            ),
        ]);
    }

    /**
     * Repost a closed or expired job: a new copy, live today (JobRepost).
     * The copy is a new job post against the plan's limit.
     */
    public function repost(JobListing $job): JsonResponse
    {
        $this->authorize('update', $job);

        $result = JobRepost::repost($job);

        if (is_string($result)) {
            $code = $result === EmployerVerification::blockMessage($job->employer) ? 'verification_required' : 'cannot_repost';

            return response()->json(['message' => $result, 'code' => $code], 422);
        }

        return response()->json([
            'message' => __('Job reposted.'),
            'job' => new EmployerJobResource($result->loadCount('applications')),
        ], 201);
    }

    /**
     * What the job form offers: each category's skills, the perks to pick
     * from (the usual ones plus this employer's own), and the shifts.
     */
    public function formOptions(Request $request): JsonResponse
    {
        return response()->json(JobFormOptions::for($request->user()));
    }
}
