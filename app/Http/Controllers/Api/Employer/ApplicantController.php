<?php

namespace App\Http\Controllers\Api\Employer;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApplicantResource;
use App\Jobs\ScoreApplication;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Notifications\ApplicationStatusNotification;
use App\Notifications\InterviewScheduledNotification;
use App\Notifications\ShortlistedNotification;
use App\Services\CreditWallet;
use App\Support\ReferenceData;
use App\Support\TemplatedMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Applicant management for the employer app's "Manage Job" screen — listing,
 * shortlisting, hiring/rejecting and unlocking contact. Notifications and
 * transactional emails mirror the web Employer\ApplicantController exactly.
 */
class ApplicantController extends Controller
{
    private const CONTACT_FIELDS = 'worker:id,name,email,phone';

    /**
     * Applicants for one of the employer's jobs, filtered by stage tab.
     */
    public function index(Request $request, JobListing $job): AnonymousResourceCollection
    {
        $this->authorize('view', $job);

        $filters = $request->validate([
            'stage' => ['nullable', 'string', 'in:all,pending,shortlisted,interview,hired,rejected'],
            'sort' => ['nullable', 'string', 'in:best_match,recent'],
        ]);
        $stage = $filters['stage'] ?? 'all';
        $sort = $filters['sort'] ?? 'best_match';

        $applications = $job->applications()
            ->with(self::CONTACT_FIELDS, 'worker.workerProfile', 'worker.kyc')
            ->when($stage !== 'all', fn ($q) => $this->scopeStage($q, $stage))
            // Best-match sorts by AI score (unscored applicants fall to the bottom).
            ->when($sort === 'best_match', fn ($q) => $q->orderByRaw('ai_score DESC NULLS LAST'))
            ->latest()
            ->paginate(20);

        return ApplicantResource::collection($applications)->additional([
            'counts' => $this->stageCounts($job),
        ]);
    }

    /**
     * Everyone the employer has shortlisted, across all of their jobs — the
     * app's standalone "Shortlisted" screen. The per-job tab is
     * {@see index()} with stage=shortlisted; this one is not scoped to a job,
     * and keeps hired/rejected people out of the way.
     */
    public function shortlisted(Request $request): AnonymousResourceCollection
    {
        $applications = JobApplication::whereNotNull('shortlisted_at')
            ->whereHas('job', fn ($q) => $q->where('employer_id', $request->user()->employerAccount()->id))
            ->with(self::CONTACT_FIELDS, 'worker.workerProfile', 'worker.kyc', 'job:id,title,city,state')
            ->orderByDesc('shortlisted_at')
            ->paginate(20);

        return ApplicantResource::collection($applications);
    }

    /**
     * A single applicant's full profile for the employer.
     */
    public function show(JobApplication $application): ApplicantResource
    {
        $this->authorize('view', $application->job);

        $application->load(self::CONTACT_FIELDS, 'worker.workerProfile', 'worker.kyc', 'job:id,title');

        return new ApplicantResource($application);
    }

    /**
     * Hire (accept) or reject an applicant. Notifies the worker in-app + email.
     */
    public function updateStatus(Request $request, JobApplication $application): JsonResponse
    {
        $this->authorize('update', $application->job);

        $data = $request->validate([
            'status' => ['required', 'in:accepted,rejected'],
            // Hire-sheet fields, only meaningful on an accept.
            'offered_wage' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'start_date' => ['nullable', 'date'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $status = ApplicationStatus::from($data['status']);

        $application->update([
            'status' => $status,
            'status_changed_at' => now(),
            ...($status === ApplicationStatus::Accepted ? [
                'offered_wage' => $data['offered_wage'] ?? $application->offered_wage,
                'start_date' => $data['start_date'] ?? $application->start_date,
                'offer_message' => $data['message'] ?? $application->offer_message,
            ] : []),
        ]);
        $application->loadMissing('job.employer', 'worker.workerProfile', 'worker.kyc');
        $application->worker->notify(new ApplicationStatusNotification($application));

        $job = $application->job;
        $key = $application->status === ApplicationStatus::Accepted
            ? 'application_accepted'
            : 'application_rejected';

        TemplatedMailer::send($key, $application->worker->email, [
            'worker_name' => $application->worker->name,
            'employer_name' => $job->employer->name,
            'job_title' => $job->title,
            'job_location' => trim(implode(', ', array_filter([$job->city, $job->state]))),
            'expected_wage' => $application->expected_wage !== null ? '₹'.number_format((float) $application->expected_wage) : '—',
            'cover_note' => $application->cover_note ?: '—',
            'action_url' => $application->status === ApplicationStatus::Accepted
                ? url('/worker/applications')
                : url('/worker/jobs'),
        ]);

        return response()->json([
            'message' => __('Applicant :status.', ['status' => $application->status->label()]),
            'applicant' => new ApplicantResource($application),
        ]);
    }

    /**
     * Shortlist / un-shortlist an applicant. Shortlisting notifies the worker.
     */
    public function toggleShortlist(JobApplication $application): JsonResponse
    {
        $this->authorize('view', $application->job);

        if ($application->shortlisted_at !== null) {
            $application->update(['shortlisted_at' => null]);
            $application->loadMissing(self::CONTACT_FIELDS, 'worker.workerProfile', 'worker.kyc', 'job:id,title');

            return response()->json([
                'message' => __('Removed from shortlist.'),
                'applicant' => new ApplicantResource($application),
            ]);
        }

        $application->update(['shortlisted_at' => now()]);
        $application->loadMissing('job.employer', 'worker.workerProfile', 'worker.kyc');
        $application->worker->notify(new ShortlistedNotification($application));

        $job = $application->job;
        TemplatedMailer::send('application_shortlisted', $application->worker->email, [
            'worker_name' => $application->worker->name,
            'employer_name' => $job->employer->name,
            'job_title' => $job->title,
            'job_location' => trim(implode(', ', array_filter([$job->city, $job->state]))),
            'expected_wage' => $application->expected_wage !== null ? '₹'.number_format((float) $application->expected_wage) : '—',
            'cover_note' => $application->cover_note ?: '—',
            'action_url' => url('/worker/applications'),
        ]);

        return response()->json([
            'message' => __('Applicant shortlisted — the worker has been notified.'),
            'applicant' => new ApplicantResource($application),
        ]);
    }

    /**
     * Schedule (or reschedule) an interview — moves the applicant into the
     * Interview stage and invites the worker.
     */
    public function scheduleInterview(Request $request, JobApplication $application): JsonResponse
    {
        $this->authorize('update', $application->job);

        $data = $request->validate([
            'interview_at' => ['required', 'date'],
            'mode' => ['required', 'string', 'in:'.implode(',', ReferenceData::INTERVIEW_MODES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $application->update([
            'interview_at' => $data['interview_at'],
            'interview_mode' => $data['mode'],
            'interview_note' => $data['note'] ?? null,
            // Interviewing implies the applicant is shortlisted.
            'shortlisted_at' => $application->shortlisted_at ?? now(),
        ]);

        $application->loadMissing('job.employer', self::CONTACT_FIELDS, 'worker.workerProfile', 'worker.kyc');
        $application->worker->notify(new InterviewScheduledNotification($application));

        return response()->json([
            'message' => __('Interview invite sent.'),
            'applicant' => new ApplicantResource($application),
        ]);
    }

    /**
     * Cancel a scheduled interview (back to the shortlisted stage).
     */
    public function cancelInterview(JobApplication $application): JsonResponse
    {
        $this->authorize('update', $application->job);

        $application->update([
            'interview_at' => null,
            'interview_mode' => null,
            'interview_note' => null,
        ]);

        $application->loadMissing(self::CONTACT_FIELDS, 'worker.workerProfile', 'worker.kyc', 'job:id,title');

        return response()->json([
            'message' => __('Interview cancelled.'),
            'applicant' => new ApplicantResource($application),
        ]);
    }

    /**
     * Reveal an applicant's contact details, spending one contact credit.
     */
    public function unlockContact(Request $request, JobApplication $application): JsonResponse
    {
        $this->authorize('update', $application->job);

        if ($application->contact_unlocked) {
            $application->loadMissing(self::CONTACT_FIELDS, 'worker.workerProfile', 'worker.kyc', 'job:id,title');

            return response()->json(['applicant' => new ApplicantResource($application)]);
        }

        $wallet = CreditWallet::for($request->user());

        if (! $wallet->canUnlock()) {
            return response()->json([
                'message' => __('You have reached your plan\'s contact unlock limit.'),
                'code' => 'out_of_credits',
                'credits' => $wallet->summary(),
            ], 422);
        }

        $wallet->consumeUnlock();

        $application->update(['contact_unlocked' => true]);
        $application->loadMissing(self::CONTACT_FIELDS, 'worker.workerProfile', 'worker.kyc', 'job:id,title');

        return response()->json([
            'message' => __('Contact unlocked.'),
            'applicant' => new ApplicantResource($application),
            'credits' => CreditWallet::for($request->user())->summary(),
        ]);
    }

    /**
     * (Re)run AI scoring for a job's applicants. By default only unscored ones
     * are queued; pass force=1 to re-score everyone.
     */
    public function rescore(Request $request, JobListing $job): JsonResponse
    {
        $this->authorize('update', $job);

        $force = $request->boolean('force');

        $ids = $job->applications()
            ->when(! $force, fn ($q) => $q->whereNull('ai_scored_at'))
            ->pluck('id');

        foreach ($ids as $id) {
            ScoreApplication::dispatch((int) $id);
        }

        return response()->json([
            'message' => trans_choice(':count applicant queued for AI scoring.|:count applicants queued for AI scoring.', $ids->count(), ['count' => $ids->count()]),
            'queued' => $ids->count(),
        ]);
    }

    /**
     * Per-stage applicant counts for the segmented tabs.
     *
     * @return array<string, int>
     */
    private function stageCounts(JobListing $job): array
    {
        $counts = ['all' => $job->applications()->count()];

        foreach (['pending', 'shortlisted', 'interview', 'hired', 'rejected'] as $stage) {
            $counts[$stage] = $this->scopeStage($job->applications(), $stage)->count();
        }

        return $counts;
    }

    /**
     * Constrain a query to one pipeline stage. The stages are exclusive so the
     * segmented tabs add up: New → Shortlisted → Interview → Hired / Rejected.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder<JobApplication>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function scopeStage($query, string $stage)
    {
        return match ($stage) {
            'pending' => $query->where('status', ApplicationStatus::Pending)
                ->whereNull('shortlisted_at')
                ->whereNull('interview_at'),
            'shortlisted' => $query->whereNotNull('shortlisted_at')
                ->whereNull('interview_at')
                ->whereNotIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Rejected]),
            'interview' => $query->whereNotNull('interview_at')
                ->whereNotIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Rejected]),
            'hired' => $query->where('status', ApplicationStatus::Accepted),
            'rejected' => $query->where('status', ApplicationStatus::Rejected),
            default => $query,
        };
    }
}
