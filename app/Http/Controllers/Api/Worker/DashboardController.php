<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\JobResource;
use App\Http\Resources\Api\WorkerProfileResource;
use App\Models\JobListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Everything the worker home screen needs in one call.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->workerProfile()->firstOrCreate([]);
        $profile->setRelation('user', $user);
        $kyc = $user->kyc;

        $latest = JobListing::active()->with('employer:id,name')->latest()->limit(5)->get();

        return response()->json([
            'greeting' => $user->name,
            'profile' => new WorkerProfileResource($profile),
            'stats' => [
                'available_jobs' => JobListing::active()->count(),
                'applications' => $user->applications()->count(),
                'saved_jobs' => $user->savedJobs()->count(),
                'kyc_status' => $kyc?->status->value ?? 'not_submitted',
                'kyc_status_label' => $kyc?->status->label() ?? 'Not submitted',
                'profile_completion' => (new WorkerProfileResource($profile))->toArray($request)['completion'],
                'unread_notifications' => $user->unreadNotifications()->count(),
            ],
            'latest_jobs' => JobResource::collection($latest),
        ]);
    }
}
