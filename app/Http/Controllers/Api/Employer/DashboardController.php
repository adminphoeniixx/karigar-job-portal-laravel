<?php

namespace App\Http\Controllers\Api\Employer;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApplicantResource;
use App\Http\Resources\Api\EmployerJobResource;
use App\Http\Resources\Api\EmployerProfileResource;
use App\Models\JobApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Everything the employer home screen needs in one call.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $account = $user->employerAccount();
        $profile = $account->employerProfile()->firstOrCreate([]);
        $profile->setRelation('user', $account);

        $jobs = $account->jobListings();
        $jobIds = (clone $jobs)->pluck('id');

        $applications = JobApplication::whereIn('job_listing_id', $jobIds);

        $recentJobs = (clone $jobs)
            ->where('status', JobStatus::Active)
            ->withCount([
                'applications',
                'applications as shortlisted_count' => fn ($q) => $q->whereNotNull('shortlisted_at'),
                'applications as hired_count' => fn ($q) => $q->where('status', ApplicationStatus::Accepted),
            ])
            ->latest()
            ->limit(5)
            ->get();

        $recentApplicants = JobApplication::whereIn('job_listing_id', $jobIds)
            ->with('worker:id,name,email,phone', 'worker.workerProfile', 'worker.kyc', 'job:id,title')
            ->latest()
            ->limit(5)
            ->get();

        return response()->json([
            'greeting' => $account->name,
            'profile' => new EmployerProfileResource($profile),
            'stats' => [
                'active_jobs' => (clone $jobs)->where('status', JobStatus::Active)->count(),
                'total_applicants' => (clone $applications)->count(),
                'shortlisted' => (clone $applications)->whereNotNull('shortlisted_at')->count(),
                'hired' => (clone $applications)->where('status', ApplicationStatus::Accepted)->count(),
                'unread_notifications' => $user->unreadNotifications()->count(),
                'verified' => $account->kyc?->status->value === 'verified',
                'profile_completion' => (new EmployerProfileResource($profile))->toArray($request)['completion'],
            ],
            'active_jobs' => EmployerJobResource::collection($recentJobs),
            'recent_applicants' => ApplicantResource::collection($recentApplicants),
        ]);
    }
}
