<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\JobBrowseController;
use App\Http\Resources\Api\JobDetailResource;
use App\Http\Resources\Api\JobResource;
use App\Models\JobListing;
use App\Support\JobSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Job browsing & detail for the worker app. Search/filter logic is shared
 * with the web via JobSearch + JobBrowseController::validateFilters.
 */
class JobController extends Controller
{
    /**
     * Paginated, filtered list of active jobs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = JobBrowseController::validateFilters($request);

        return JobResource::collection(JobSearch::paginate($filters));
    }

    /**
     * Single active job with the worker's own context (applied? saved?).
     */
    public function show(Request $request, JobListing $job): JobDetailResource
    {
        abort_unless($job->status->value === 'active', 404);

        $job->load('employer:id,name');
        $user = $request->user();
        $application = $job->applications()->where('worker_id', $user->id)->first();

        return (new JobDetailResource($job))->additional([
            'meta' => [
                'employer_rating' => $job->employer ? [
                    'average' => $job->employer->averageRating(),
                    'count' => $job->employer->reviewsReceived()->count(),
                ] : null,
                'application' => $application ? [
                    'status' => $application->status->value,
                    'status_label' => $application->status->label(),
                    'created_ago' => $application->created_at?->diffForHumans(),
                ] : null,
                'is_saved' => $user->savedJobs()->where('job_listing_id', $job->id)->exists(),
                'can_apply' => $application === null,
            ],
        ]);
    }
}
