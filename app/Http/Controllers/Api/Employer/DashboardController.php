<?php

namespace App\Http\Controllers\Api\Employer;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApplicantResource;
use App\Http\Resources\Api\EmployerJobResource;
use App\Http\Resources\Api\EmployerProfileResource;
use App\Models\ChatMessage;
use App\Models\JobApplication;
use App\Models\Setting;
use App\Services\CreditWallet;
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
                'applications as interview_count' => fn ($q) => $q->whereNotNull('interview_at')
                    ->whereNotIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Rejected]),
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
            // Contact-credit card on the home screen.
            'credits' => CreditWallet::for($account)->summary(),
            'stats' => [
                'active_jobs' => (clone $jobs)->where('status', JobStatus::Active)->count(),
                'total_applicants' => (clone $applications)->count(),
                'shortlisted' => (clone $applications)->whereNotNull('shortlisted_at')->count(),
                'hired' => (clone $applications)->where('status', ApplicationStatus::Accepted)->count(),
                'interview' => (clone $applications)->whereNotNull('interview_at')
                    ->whereNotIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Rejected])->count(),
                'unread_notifications' => $user->unreadNotifications()->count(),
                'unread_messages' => ChatMessage::whereNull('read_at')
                    ->whereHas('conversation', fn ($q) => $q->where('employer_id', $account->id)
                        ->whereColumn('conversations.worker_id', 'chat_messages.sender_id'))
                    ->count(),
                'verified' => $account->isKycVerified(),
                'profile_completion' => (new EmployerProfileResource($profile))->toArray($request)['completion'],
            ],
            // Admin-controlled feature flags; the app hides its KYC screens when
            // verification_enabled is false.
            'features' => [
                'verification_enabled' => Setting::bool('kyc_verification_enabled', true),
            ],
            'active_jobs' => EmployerJobResource::collection($recentJobs),
            'recent_applicants' => ApplicantResource::collection($recentApplicants),
        ]);
    }
}
