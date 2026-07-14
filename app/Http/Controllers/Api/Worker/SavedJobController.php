<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\SavedJobResource;
use App\Models\JobListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SavedJobController extends Controller
{
    /**
     * The worker's saved/bookmarked jobs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $saved = $request->user()->savedJobs()
            ->with('job', 'job.employer:id,name')
            ->latest()
            ->paginate(15);

        return SavedJobResource::collection($saved);
    }

    /**
     * Toggle a job in the saved list. Returns the resulting state.
     */
    public function toggle(Request $request, JobListing $job): JsonResponse
    {
        $existing = $request->user()->savedJobs()->where('job_listing_id', $job->id)->first();

        if ($existing) {
            $existing->delete();

            return response()->json(['saved' => false, 'message' => __('Removed from saved.')]);
        }

        $request->user()->savedJobs()->create(['job_listing_id' => $job->id]);

        return response()->json(['saved' => true, 'message' => __('Saved.')]);
    }
}
