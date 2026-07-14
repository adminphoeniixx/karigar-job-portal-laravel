<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\Auth\OtpAuthController;
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
    });
});
