<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\JobBrowseController;
use App\Models\JobListing;
use App\Support\JobSearch;
use Illuminate\Http\Request;
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

        return Inertia::render('worker/Jobs', [
            'jobs' => JobSearch::paginate($filters),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, JobListing $job): Response
    {
        abort_unless($job->status->value === 'active', 404);

        $job->load('employer:id,name');

        $user = $request->user();
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
            'isSaved' => $user->savedJobs()->where('job_listing_id', $job->id)->exists(),
        ]);
    }
}
