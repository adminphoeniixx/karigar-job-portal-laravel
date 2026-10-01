<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\JobBrowseController;
use App\Http\Resources\Api\JobDetailResource;
use App\Http\Resources\Api\JobResource;
use App\Models\JobListing;
use App\Support\JobFeed;
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
     * The job list. Opened plain, it is the karigar's own feed (JobFeed):
     * their categories, nearest first from `lat`/`lng`, the phone's current
     * position. A search or filter they choose goes to the full search.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = JobBrowseController::validateFilters($request);
        $all = $request->validate(['all' => ['nullable', 'boolean']])['all'] ?? false;

        $searching = collect(['q', 'state', 'city', 'category', 'skill'])
            ->contains(fn (string $key) => filled($filters[$key] ?? null));

        if ($searching) {
            return JobResource::collection(JobSearch::paginate($filters));
        }

        $feed = JobFeed::for(
            $request->user(),
            isset($filters['lat'], $filters['lng']) ? (float) $filters['lat'] : null,
            isset($filters['lat'], $filters['lng']) ? (float) $filters['lng'] : null,
            (bool) $all,
        );

        $jobs = $feed->query(isset($filters['radius']) ? (float) $filters['radius'] : null)
            ->paginate(15)
            ->withQueryString();

        return JobResource::collection($jobs)->additional(['feed' => $feed->meta()]);
    }

    /**
     * Single active job with the worker's own context (applied? saved?).
     */
    public function show(Request $request, JobListing $job): JobDetailResource
    {
        abort_unless($job->status->value === 'active', 404);

        // Feeds the employer's job-funnel "Views" metric.
        $job->incrementQuietly('views_count');

        $job->load('employer:id,name', 'employer.kyc');
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
