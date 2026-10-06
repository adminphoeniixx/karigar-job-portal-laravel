<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\JobBrowseController;
use App\Models\JobListing;
use App\Support\JobSearch;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-app (sidebar) job browsing & detail for logged-in workers. Mirrors the
 * public JobBrowseController but renders inside the authenticated AppLayout.
 */
class JobController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = JobBrowseController::validateFilters($request);

        // Not available for work: no jobs, just the switch to come back.
        $unavailable = $request->user()->isUnavailableWorker();

        return Inertia::render('worker/Jobs', [
            'jobs' => $unavailable
                ? new LengthAwarePaginator([], 0, 15)
                : JobSearch::paginate($filters),
            'filters' => $filters,
            'unavailable' => $unavailable,
        ]);
    }

    public function show(Request $request, JobListing $job): Response
    {
        $user = $request->user();
        // Paused, expired or closed jobs are gone except for workers who applied.
        abort_unless($job->isViewableBy($user), 404);

        $job->load('employer:id,name');

        $application = $job->applications()->where('worker_id', $user->id)->first();

        return Inertia::render('worker/JobShow', [
            'job' => $job,
            'employerRating' => $job->employer ? [
                'average' => $job->employer->averageRating(),
                'count' => $job->employer->reviewsReceived()->count(),
            ] : null,
            'application' => $application ? [
                'status' => $application->status->value,
                'created_at' => $application->created_at?->diffForHumans(),
                'tracking_steps' => $application->trackingSteps(),
            ] : null,
            'isOpen' => $job->isOpenForApplications(),
            'isSaved' => $user->savedJobs()->where('job_listing_id', $job->id)->exists(),
            // Offered right inside the apply panel: applying is when a worker
            // cares that the employer's AI will read their resume.
            'resume' => $user->workerProfile?->resumeSummary(),
        ]);
    }
}
