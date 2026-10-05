<?php

namespace App\Http\Controllers;

use App\Models\JobListing;
use App\Support\JobSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JobBrowseController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->validateFilters($request);

        return Inertia::render('jobs/Browse', [
            'jobs' => JobSearch::paginate($filters),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, JobListing $job): Response
    {
        $user = $request->user();
        // Paused, expired or closed jobs are gone except for workers who applied.
        abort_unless($job->isViewableBy($user), 404);
        $open = $job->isOpenForApplications();

        // Feeds the employer's job-funnel "Views" metric.
        if ($open) {
            $job->incrementQuietly('views_count');
        }

        $job->load('employer:id,name');

        $isWorker = $user?->isWorker() ?? false;

        $application = $isWorker
            ? $job->applications()->where('worker_id', $user->id)->first()
            : null;

        return Inertia::render('jobs/Show', [
            'job' => $job,
            'canApply' => $isWorker && $open,
            'isOpen' => $open,
            'application' => $application ? [
                'status' => $application->status->value,
                'created_at' => $application->created_at?->diffForHumans(),
            ] : null,
            'isSaved' => $user ? $user->savedJobs()->where('job_listing_id', $job->id)->exists() : false,
            // Same as the in-app job page: the apply panel offers the resume.
            'resume' => $isWorker ? $user->workerProfile?->resumeSummary() : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validateFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'skill' => ['nullable', 'string', 'max:50'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:500'],
        ]);
    }
}
