<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\JobResource;
use App\Http\Resources\Api\WorkerProfileResource;
use App\Models\JobListing;
use App\Models\Setting;
use App\Support\JobFeed;
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
        $verificationEnabled = Setting::bool('kyc_verification_enabled', true);
        $kyc = $verificationEnabled ? $user->kyc : null;

        // The top of the karigar's own feed: their categories, nearest first
        // from the phone's position when the app sends it.
        $point = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);
        $latest = JobFeed::for(
            $user,
            isset($point['lat']) ? (float) $point['lat'] : null,
            isset($point['lng']) ? (float) $point['lng'] : null,
        )->query()->limit(5)->get();

        return response()->json([
            'greeting' => $user->name,
            'profile' => new WorkerProfileResource($profile),
            'stats' => [
                'available_jobs' => JobListing::active()->hiring()->count(),
                'applications' => $user->applications()->count(),
                'saved_jobs' => $user->savedJobs()->count(),
                // null while verification is switched off, so the app shows no
                // KYC prompt at all rather than a misleading "Not submitted".
                'kyc_status' => $verificationEnabled ? ($kyc?->status->value ?? 'not_submitted') : null,
                'kyc_status_label' => $verificationEnabled ? ($kyc?->status->label() ?? 'Not submitted') : null,
                'profile_completion' => (new WorkerProfileResource($profile))->toArray($request)['completion'],
                'unread_notifications' => $user->unreadNotifications()->count(),
            ],
            // Admin-controlled feature flags; the app hides its KYC screens when
            // verification_enabled is false.
            'features' => [
                'verification_enabled' => $verificationEnabled,
            ],
            'latest_jobs' => JobResource::collection($latest),
        ]);
    }
}
