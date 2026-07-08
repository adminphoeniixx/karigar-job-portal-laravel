<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Notifications\NewApplicationNotification;
use App\Support\TemplatedMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JobApplicationController extends Controller
{
    /**
     * The worker's own applications.
     */
    public function index(Request $request): Response
    {
        $applications = $request->user()->applications()
            ->with('job:id,title,city,state,employer_id', 'job.employer:id,name')
            ->latest()
            ->paginate(15);

        return Inertia::render('applications/Index', [
            'applications' => $applications,
        ]);
    }

    /**
     * Worker applies to a job.
     */
    public function store(Request $request, JobListing $job): RedirectResponse
    {
        abort_unless($job->status === JobStatus::Active, 404);

        $data = $request->validate([
            'cover_note' => ['nullable', 'string', 'max:1000'],
            'expected_wage' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $worker = $request->user();

        if ($job->applications()->where('worker_id', $worker->id)->exists()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => __('You have already applied to this job.'),
            ]);
        }

        $application = $job->applications()->create([
            'worker_id' => $worker->id,
            'cover_note' => $data['cover_note'] ?? null,
            'expected_wage' => $data['expected_wage'] ?? null,
            'status' => ApplicationStatus::Pending,
        ]);

        $job->employer->notify(new NewApplicationNotification($application));

        // Transactional emails (templates are admin-editable at /admin/email-templates).
        $mailData = [
            'worker_name' => $worker->name,
            'employer_name' => $job->employer->name,
            'job_title' => $job->title,
            'job_location' => trim(implode(', ', array_filter([$job->city, $job->state]))),
            'expected_wage' => $application->expected_wage !== null ? '₹'.number_format((float) $application->expected_wage) : '—',
            'cover_note' => $application->cover_note ?: '—',
        ];

        TemplatedMailer::send('application_received', $job->employer->email, $mailData + [
            'action_url' => url("/employer/jobs/{$job->id}/applicants"),
        ]);

        TemplatedMailer::send('application_submitted', $worker->email, $mailData + [
            'action_url' => url('/worker/applications'),
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Application submitted.'),
        ]);
    }

    /**
     * Worker withdraws a pending/accepted application.
     */
    public function withdraw(Request $request, JobApplication $application): RedirectResponse
    {
        abort_unless($application->worker_id === $request->user()->id, 403);

        $application->update(['status' => ApplicationStatus::Withdrawn]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Application withdrawn.'),
        ]);
    }
}
