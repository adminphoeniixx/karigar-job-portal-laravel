<?php

namespace App\Http\Controllers\Employer;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Notifications\ApplicationStatusNotification;
use App\Support\TemplatedMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApplicantController extends Controller
{
    /**
     * Applicants for one of the employer's jobs.
     */
    public function index(Request $request, JobListing $job): Response
    {
        $this->authorize('update', $job);

        $applications = $job->applications()
            ->with('worker:id,name,email', 'worker.workerProfile', 'escrow')
            ->latest()
            ->get()
            ->map(fn (JobApplication $a) => [
                'id' => $a->id,
                'status' => $a->status->value,
                'cover_note' => $a->cover_note,
                'expected_wage' => $a->expected_wage,
                'contact_unlocked' => $a->contact_unlocked,
                'created_at' => $a->created_at?->diffForHumans(),
                'escrow' => $a->escrow ? [
                    'id' => $a->escrow->id,
                    'status' => $a->escrow->status->value,
                    'status_label' => $a->escrow->status->label(),
                    'amount' => $a->escrow->amount,
                    'payout_amount' => $a->escrow->payout_amount,
                ] : null,
                'worker' => [
                    'id' => $a->worker->id,
                    'name' => $a->worker->name,
                    'rating' => $a->worker->averageRating(),
                    'skills' => $a->worker->workerProfile?->skills ?? [],
                    'city' => $a->worker->workerProfile?->city,
                    'state' => $a->worker->workerProfile?->state,
                    'experience_years' => $a->worker->workerProfile?->experience_years,
                    // Contact details are only revealed once unlocked.
                    'email' => $a->contact_unlocked ? $a->worker->email : null,
                    'phone' => $a->contact_unlocked ? $a->worker->workerProfile?->phone : null,
                ],
            ]);

        return Inertia::render('applicants/Index', [
            'job' => $job->only('id', 'title'),
            'applications' => $applications,
            'contactUnlocks' => [
                'used' => $this->unlocksUsed($request),
                'limit' => $request->user()->activeSubscription()?->plan->contactUnlockLimit() ?? 0,
            ],
        ]);
    }

    /**
     * Accept or reject an applicant.
     */
    public function updateStatus(Request $request, JobApplication $application): RedirectResponse
    {
        $this->authorize('update', $application->job);

        $data = $request->validate([
            'status' => ['required', 'in:accepted,rejected'],
        ]);

        $application->update(['status' => ApplicationStatus::from($data['status'])]);
        $application->loadMissing('job.employer', 'worker');
        $application->worker->notify(new ApplicationStatusNotification($application));

        // Transactional email to the worker (template admin-editable at /admin/email-templates).
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

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Applicant :status.', ['status' => $application->status->label()]),
        ]);
    }

    /**
     * Reveal an applicant's contact details, consuming one unlock from the plan.
     */
    public function unlockContact(Request $request, JobApplication $application): RedirectResponse
    {
        $this->authorize('update', $application->job);

        if ($application->contact_unlocked) {
            return back();
        }

        $limit = $request->user()->activeSubscription()?->plan->contactUnlockLimit() ?? 0;

        if ($limit > 0 && $this->unlocksUsed($request) >= $limit) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => __('You have reached your plan\'s contact unlock limit.'),
            ]);
        }

        $application->update(['contact_unlocked' => true]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Contact unlocked.'),
        ]);
    }

    /**
     * How many contact unlocks the employer has consumed across all their jobs.
     */
    private function unlocksUsed(Request $request): int
    {
        return JobApplication::where('contact_unlocked', true)
            ->whereHas('job', fn ($q) => $q->where('employer_id', $request->user()->id))
            ->count();
    }
}
