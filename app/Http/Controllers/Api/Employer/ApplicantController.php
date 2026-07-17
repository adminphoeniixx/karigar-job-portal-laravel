<?php

namespace App\Http\Controllers\Api\Employer;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApplicantResource;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Notifications\ApplicationStatusNotification;
use App\Notifications\ShortlistedNotification;
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
            'stage' => ['nullable', 'string', 'in:all,pending,shortlisted,hired,rejected'],
        ]);
        $stage = $filters['stage'] ?? 'all';

        $applications = $job->applications()
            ->with(self::CONTACT_FIELDS, 'worker.workerProfile', 'worker.kyc')
            ->when($stage === 'pending', fn ($q) => $q->where('status', ApplicationStatus::Pending)->whereNull('shortlisted_at'))
            ->when($stage === 'shortlisted', fn ($q) => $q->whereNotNull('shortlisted_at')->where('status', '!=', ApplicationStatus::Accepted))
            ->when($stage === 'hired', fn ($q) => $q->where('status', ApplicationStatus::Accepted))
            ->when($stage === 'rejected', fn ($q) => $q->where('status', ApplicationStatus::Rejected))
            ->latest()
            ->paginate(20);

        return ApplicantResource::collection($applications)->additional([
            'counts' => $this->stageCounts($job),
        ]);
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
        ]);

        $application->update(['status' => ApplicationStatus::from($data['status'])]);
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
     * Reveal an applicant's contact details, consuming one plan unlock.
     */
    public function unlockContact(Request $request, JobApplication $application): JsonResponse
    {
        $this->authorize('update', $application->job);

        if ($application->contact_unlocked) {
            $application->loadMissing(self::CONTACT_FIELDS, 'worker.workerProfile', 'worker.kyc', 'job:id,title');

            return response()->json(['applicant' => new ApplicantResource($application)]);
        }

        $account = $request->user()->employerAccount();
        $limit = $account->activeSubscription()?->plan->contactUnlockLimit() ?? 0;
        $used = JobApplication::where('contact_unlocked', true)
            ->whereHas('job', fn ($q) => $q->where('employer_id', $account->id))
            ->count();

        if ($limit > 0 && $used >= $limit) {
            return response()->json([
                'message' => __('You have reached your plan\'s contact unlock limit.'),
            ], 422);
        }

        $application->update(['contact_unlocked' => true]);
        $application->loadMissing(self::CONTACT_FIELDS, 'worker.workerProfile', 'worker.kyc', 'job:id,title');

        return response()->json([
            'message' => __('Contact unlocked.'),
            'applicant' => new ApplicantResource($application),
        ]);
    }

    /**
     * Per-stage applicant counts for the segmented tabs.
     *
     * @return array<string, int>
     */
    private function stageCounts(JobListing $job): array
    {
        return [
            'all' => $job->applications()->count(),
            'pending' => $job->applications()->where('status', ApplicationStatus::Pending)->whereNull('shortlisted_at')->count(),
            'shortlisted' => $job->applications()->whereNotNull('shortlisted_at')->where('status', '!=', ApplicationStatus::Accepted)->count(),
            'hired' => $job->applications()->where('status', ApplicationStatus::Accepted)->count(),
            'rejected' => $job->applications()->where('status', ApplicationStatus::Rejected)->count(),
        ];
    }
}
