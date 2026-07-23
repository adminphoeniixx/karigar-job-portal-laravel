<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\Auth\OtpAuthController;
use App\Http\Controllers\Api\Employer\ApplicantController as EmployerApplicantController;
use App\Http\Controllers\Api\Employer\DashboardController as EmployerDashboardController;
use App\Http\Controllers\Api\Employer\JobController as EmployerJobController;
use App\Http\Controllers\Api\Employer\KycController as EmployerKycController;
use App\Http\Controllers\Api\Employer\ProfileController as EmployerProfileController;
use App\Http\Controllers\Api\Employer\ReviewController as EmployerReviewController;
use App\Http\Controllers\Api\Employer\TeamController as EmployerTeamController;
use App\Http\Controllers\Api\Employer\WorkerDirectoryController;
use App\Http\Controllers\Api\LocaleController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\Worker\ApplicationController;
use App\Http\Controllers\Api\Worker\DashboardController;
use App\Http\Controllers\Api\Worker\JobController;
use App\Http\Controllers\Api\Worker\KycController;
use App\Http\Controllers\Api\Worker\NotificationController;
use App\Http\Controllers\Api\Worker\ProfileController;
use App\Http\Controllers\Api\Worker\ReviewController;
use App\Http\Controllers\Api\Worker\SavedJobController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Worker mobile app API (v1)
|--------------------------------------------------------------------------
| Token-based (Sanctum). All authenticated worker routes require
| `auth:sanctum` + `role:worker`. OTP + reference data are public.
*/

Route::prefix('v1')->group(function () {

    // ---- Public: OTP auth ----
    Route::prefix('auth')->group(function () {
        Route::post('otp/send', [OtpAuthController::class, 'send'])
            ->middleware('throttle:6,1')->name('api.otp.send');
        Route::post('otp/verify', [OtpAuthController::class, 'verify'])
            ->middleware('throttle:10,1')->name('api.otp.verify');
    });

    // ---- Public: reference data (dropdowns / chips) ----
    Route::prefix('reference')->group(function () {
        Route::get('/', [ReferenceController::class, 'index'])->name('api.reference');
        Route::get('cities', [ReferenceController::class, 'cities'])->name('api.reference.cities');
        Route::get('job-categories', [ReferenceController::class, 'jobCategories'])->name('api.reference.categories');
    });

    // ---- Authenticated ----
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [OtpAuthController::class, 'me'])->name('api.me');
        Route::post('auth/logout', [OtpAuthController::class, 'logout'])->name('api.logout');
        Route::delete('account', [AccountController::class, 'destroy'])->name('api.account.destroy');
        Route::post('locale', [LocaleController::class, 'update'])->name('api.locale');

        // Push notification device tokens (any authenticated user)
        Route::post('device-tokens', [DeviceTokenController::class, 'store'])->name('api.device-tokens.store');
        Route::delete('device-tokens', [DeviceTokenController::class, 'destroy'])->name('api.device-tokens.destroy');

        // Notifications (any authenticated user)
        Route::get('notifications', [NotificationController::class, 'index'])->name('api.notifications');
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('api.notifications.read');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('api.notifications.readAll');

        // ---- Worker-only ----
        Route::middleware('role:worker')->group(function () {
            Route::get('worker/dashboard', [DashboardController::class, 'index'])->name('api.worker.dashboard');

            // Profile / registration
            Route::get('worker/profile', [ProfileController::class, 'show'])->name('api.worker.profile.show');
            Route::match(['put', 'patch'], 'worker/profile', [ProfileController::class, 'update'])->name('api.worker.profile.update');
            Route::post('worker/profile/avatar', [ProfileController::class, 'avatar'])->name('api.worker.profile.avatar');
            Route::patch('worker/availability', [ProfileController::class, 'availability'])->name('api.worker.availability');

            // Jobs
            Route::get('jobs', [JobController::class, 'index'])->name('api.jobs');
            Route::get('jobs/{job}', [JobController::class, 'show'])->name('api.jobs.show');

            // Applications
            Route::get('worker/applications', [ApplicationController::class, 'index'])->name('api.applications');
            Route::post('jobs/{job}/apply', [ApplicationController::class, 'store'])->name('api.applications.store');
            Route::delete('applications/{application}', [ApplicationController::class, 'withdraw'])->name('api.applications.withdraw');

            // Saved jobs
            Route::get('worker/saved', [SavedJobController::class, 'index'])->name('api.saved');
            Route::post('jobs/{job}/save', [SavedJobController::class, 'toggle'])->name('api.saved.toggle');

            // KYC (optional)
            Route::get('kyc', [KycController::class, 'show'])->name('api.kyc.show');
            Route::post('kyc', [KycController::class, 'store'])->name('api.kyc.store');

            // Reviews
            Route::get('worker/reviews', [ReviewController::class, 'received'])->name('api.reviews.received');
            Route::post('applications/{application}/review', [ReviewController::class, 'store'])->name('api.reviews.store');
        });

        // ---- Employer-only ----
        Route::middleware('role:employer')->group(function () {
            Route::get('employer/dashboard', [EmployerDashboardController::class, 'index'])->name('api.employer.dashboard');

            // Business profile / registration
            Route::get('employer/profile', [EmployerProfileController::class, 'show'])->name('api.employer.profile.show');
            Route::match(['put', 'patch'], 'employer/profile', [EmployerProfileController::class, 'update'])->name('api.employer.profile.update');
            Route::post('employer/profile/logo', [EmployerProfileController::class, 'logo'])->name('api.employer.profile.logo');

            // Jobs
            Route::get('employer/jobs', [EmployerJobController::class, 'index'])->name('api.employer.jobs');
            Route::post('employer/jobs', [EmployerJobController::class, 'store'])->name('api.employer.jobs.store');
            Route::get('employer/jobs/{job}', [EmployerJobController::class, 'show'])->name('api.employer.jobs.show');
            Route::match(['put', 'patch'], 'employer/jobs/{job}', [EmployerJobController::class, 'update'])->name('api.employer.jobs.update');
            Route::post('employer/jobs/{job}/close', [EmployerJobController::class, 'close'])->name('api.employer.jobs.close');
            Route::delete('employer/jobs/{job}', [EmployerJobController::class, 'destroy'])->name('api.employer.jobs.destroy');

            // Applicants
            Route::get('employer/jobs/{job}/applicants', [EmployerApplicantController::class, 'index'])->name('api.employer.applicants');
            Route::get('employer/applicants/{application}', [EmployerApplicantController::class, 'show'])->name('api.employer.applicants.show');
            Route::patch('employer/applicants/{application}/status', [EmployerApplicantController::class, 'updateStatus'])->name('api.employer.applicants.status');
            Route::post('employer/applicants/{application}/shortlist', [EmployerApplicantController::class, 'toggleShortlist'])->name('api.employer.applicants.shortlist');
            Route::post('employer/applicants/{application}/unlock', [EmployerApplicantController::class, 'unlockContact'])->name('api.employer.applicants.unlock');

            // Find workers
            Route::get('employer/workers', [WorkerDirectoryController::class, 'index'])->name('api.employer.workers');
            Route::get('employer/workers/{worker}', [WorkerDirectoryController::class, 'show'])->name('api.employer.workers.show');

            // Business verification (GST / PAN)
            Route::get('employer/kyc', [EmployerKycController::class, 'show'])->name('api.employer.kyc.show');
            Route::post('employer/kyc', [EmployerKycController::class, 'store'])->name('api.employer.kyc.store');

            // Reviews — received from workers + rate a hired worker
            Route::get('employer/reviews', [EmployerReviewController::class, 'received'])->name('api.employer.reviews');
            Route::post('employer/applicants/{application}/review', [EmployerReviewController::class, 'store'])->name('api.employer.reviews.store');

            // Team members (owner only)
            Route::get('employer/team', [EmployerTeamController::class, 'index'])->name('api.employer.team');
            Route::post('employer/team', [EmployerTeamController::class, 'store'])->name('api.employer.team.store');
            Route::patch('employer/team/{member}', [EmployerTeamController::class, 'update'])->name('api.employer.team.update');
            Route::delete('employer/team/{member}', [EmployerTeamController::class, 'destroy'])->name('api.employer.team.destroy');
        });
    });
});
