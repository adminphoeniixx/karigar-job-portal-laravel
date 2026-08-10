<?php

namespace App\Http\Controllers;

use App\Enums\JobStatus;
use App\Http\Requests\JobListingRequest;
use App\Models\JobListing;
use App\Models\User;
use App\Notifications\NewJobNotification;
use App\Services\JobDescriptionWriter;
use App\Services\JobPostingGate;
use App\Support\TemplatedMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
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

        $account = $request->user()->employerAccount();

        return Inertia::render('jobs/Form', [
            'job' => null,
            'defaultPhone' => $request->user()->employerProfile?->phone,
            // Show a "your first post is free" hint when this applies.
            'freePostAvailable' => JobPostingGate::evaluate($account)['consumesFreePost'],
        ]);
    }

    /**
     * AI-drafted descriptions for the title the employer has typed, fetched by
     * the job form when they reach the description box. Read-only from the
     * app's side — nothing is saved until they post the job, so this is open to
     * any employer (the edit form uses it too) and rate-limited on the route
     * rather than gated on the posting quota.
     */
    public function suggestDescription(Request $request, JobDescriptionWriter $writer): JsonResponse
    {
        // Validated by hand: the app only renders JSON errors under /api/* (see
        // bootstrap/app.php), and this endpoint is read by fetch(), not Inertia.
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'category' => ['nullable', 'string', 'max:80'],
            'skills' => ['nullable', 'array', 'max:20'],
            'skills.*' => ['string', 'max:60'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

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

    public function store(JobListingRequest $request): RedirectResponse
    {
        $this->authorize('create', JobListing::class);

        $account = $request->user()->employerAccount();
        $gate = JobPostingGate::evaluate($account);

        if (! $gate['allowed']) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => $gate['message'],
            ]);
        }

        $job = $account->jobListings()->create($request->validated());

        if ($gate['consumesFreePost']) {
            JobPostingGate::consumeFreePost($account);
        }

        // Notify workers about the new opening (active jobs only).
        if ($job->status === JobStatus::Active) {
            $this->notifyWorkers($job);
            $this->sendPostedEmail($job, $account);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Job posted.')]);

        return to_route('jobs.index');
    }

    /**
     * Email the employer a confirmation that their job is live. Uses the
     * admin-editable "job_posted" template; no-ops if it is missing/inactive.
     */
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
