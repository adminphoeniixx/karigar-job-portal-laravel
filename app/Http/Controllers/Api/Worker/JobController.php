<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\JobBrowseController;
use App\Http\Resources\Api\JobDetailResource;
use App\Http\Resources\Api\JobResource;
use App\Models\JobListing;
use App\Models\User;
use App\Support\JobFeed;
use App\Support\JobSearch;
use App\Support\WorkerVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

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

        if ($searching && ! $request->user()->isUnavailableWorker()) {
            return JobResource::collection(JobSearch::paginate($filters));
        }

        return self::feed(
            $request->user(),
            isset($filters['lat'], $filters['lng']) ? (float) $filters['lat'] : null,
            isset($filters['lat'], $filters['lng']) ? (float) $filters['lng'] : null,
            (bool) $all,
            isset($filters['radius']) ? (float) $filters['radius'] : null,
        );
    }

    /**
     * The first page of the karigar's own feed (or of a later page, from
     * `?page=`). Also sent back when the karigar changes where the feed
     * looks (see FeedLocationController), so the app needs no second call.
     */
    public static function feed(User $worker, ?float $lat, ?float $lng, bool $all = false, ?float $radius = null): AnonymousResourceCollection
    {
        // "Available for work" is off: no feed and no search until it is back
        // on. The app shows `message` with a button to switch it on.
        if ($worker->isUnavailableWorker()) {
            return JobResource::collection(new LengthAwarePaginator([], 0, 15))->additional([
                'unavailable' => true,
                'message' => __('You are not available for work. Switch it on to see jobs.'),
            ]);
        }

        $feed = JobFeed::for($worker, $lat, $lng, $all);

        $jobs = $feed->query($radius)
            ->paginate(15)
            // Next-page links always point at the job list, whichever
            // endpoint sent the page.
            ->withPath(route('api.jobs'))
            ->appends(array_filter([
                'lat' => $lat,
                'lng' => $lng,
                'all' => $all ? 1 : null,
                'radius' => $radius,
            ], fn ($value) => $value !== null));

        return JobResource::collection($jobs)->additional(['feed' => $feed->meta()]);
    }

    /**
     * Single active job with the worker's own context (applied? saved?).
     */
    public function show(Request $request, JobListing $job): JobDetailResource
    {
        $user = $request->user();
        // Paused (plan lapsed), expired or closed jobs are gone, except for a
        // worker who already applied and wants to see where they stand.
        abort_unless($job->isViewableBy($user), 404);
        $open = $job->isOpenForApplications();

        // Feeds the employer's job-funnel "Views" metric.
        if ($open) {
            $job->incrementQuietly('views_count');
        }

        $job->load('employer:id,name', 'employer.kyc');
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
                // false once the job stops taking applications; show "No longer hiring".
                'is_open' => $open,
                'can_apply' => $application === null && $open,
                // Whether the karigar's KYC lets them apply at all; when
                // verification.can_apply is false show its message and a
                // "Complete KYC" button instead of Apply.
                'verification' => WorkerVerification::summary($user),
            ],
        ]);
    }
}
