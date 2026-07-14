<?php

namespace App\Http\Controllers\Api\Worker;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApplicationResource;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Notifications\NewApplicationNotification;
use App\Support\TemplatedMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    /**
     * The worker's own applications, newest first. Optionally filter by status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'in:pending,accepted,rejected,withdrawn'],
        ]);

        $applications = $request->user()->applications()
            ->with('job:id,title,city,state,employer_id,created_at,expires_at', 'job.employer:id,name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate(15);

        return ApplicationResource::collection($applications);
    }

    /**
     * Apply to a job. Mirrors the web flow: notifies the employer and sends
     * both transactional emails.
     */
    public function store(Request $request, JobListing $job): JsonResponse
    {
        abort_unless($job->status === JobStatus::Active, 404);

        $data = $request->validate([
            'cover_note' => ['nullable', 'string', 'max:1000'],
            'expected_wage' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $worker = $request->user();

        if ($job->applications()->where('worker_id', $worker->id)->exists()) {
            throw ValidationException::withMessages([
                'job' => __('You have already applied to this job.'),
            ]);
        }

        $application = $job->applications()->create([
            'worker_id' => $worker->id,
            'cover_note' => $data['cover_note'] ?? null,
            'expected_wage' => $data['expected_wage'] ?? null,
            'status' => ApplicationStatus::Pending,
        ]);

        $job->loadMissing('employer');
        $job->employer->notify(new NewApplicationNotification($application));

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

        return response()->json([
            'message' => __('Application submitted.'),
            'application' => new ApplicationResource($application->load('job.employer')),
        ], 201);
    }

    /**
     * Withdraw one of the worker's own applications.
     */
    public function withdraw(Request $request, JobApplication $application): JsonResponse
    {
        abort_unless($application->worker_id === $request->user()->id, 403);

        $application->update(['status' => ApplicationStatus::Withdrawn]);

        return response()->json(['message' => __('Application withdrawn.')]);
    }
}
