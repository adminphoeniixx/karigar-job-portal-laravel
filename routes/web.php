<?php

use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DirectoryController as AdminDirectoryController;
use App\Http\Controllers\Admin\EmailTemplateController as AdminEmailTemplateController;
use App\Http\Controllers\Admin\EscrowController as AdminEscrowController;
use App\Http\Controllers\Admin\JobModerationController as AdminJobModerationController;
use App\Http\Controllers\Admin\KycController as AdminKycController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\PhoneOtpController;
use App\Http\Controllers\Auth\RoleAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Employer\ApplicantController;
use App\Http\Controllers\Employer\EscrowController;
use App\Http\Controllers\Employer\TeamController;
use App\Http\Controllers\Employer\WorkerDirectoryController;
use App\Http\Controllers\EmployerProfileController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\JobApplicationController;
use App\Http\Controllers\JobBrowseController;
use App\Http\Controllers\JobListingController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RazorpayWebhookController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SavedJobController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\Worker\JobController as WorkerJobController;
use App\Http\Controllers\WorkerProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Public job browsing
Route::get('jobs', [JobBrowseController::class, 'index'])->name('jobs.browse');
Route::get('jobs/{job}', [JobBrowseController::class, 'show'])->name('jobs.show');

// Language switch (works for guests and authenticated users)
Route::post('locale', [LocaleController::class, 'update'])->name('locale.update');

// Separate, role-specific login & register URLs (post to shared Fortify endpoints)
Route::middleware('guest')->group(function () {
    Route::get('{role}/login', [RoleAuthController::class, 'login'])
        ->whereIn('role', ['worker', 'employer', 'admin'])
        ->name('role.login');
    Route::get('{role}/register', [RoleAuthController::class, 'register'])
        ->whereIn('role', ['worker', 'employer'])
        ->name('role.register');

    // Mobile-number + OTP login/registration (MSG91)
    Route::get('{role}/otp-login', [PhoneOtpController::class, 'show'])
        ->whereIn('role', ['worker', 'employer'])
        ->name('otp.show');
    Route::post('otp/send', [PhoneOtpController::class, 'send'])->name('otp.send');
    Route::post('{role}/otp/verify', [PhoneOtpController::class, 'verify'])
        ->whereIn('role', ['worker', 'employer'])
        ->name('otp.verify');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

Route::middleware(['auth'])->group(function () {
    // Worker profile
    Route::middleware('role:worker')->group(function () {
        Route::get('worker/profile', [WorkerProfileController::class, 'edit'])->name('worker.profile.edit');
        Route::patch('worker/profile', [WorkerProfileController::class, 'update'])->name('worker.profile.update');
    });

    // Employer profile
    Route::middleware('role:employer')->group(function () {
        Route::get('employer/profile', [EmployerProfileController::class, 'edit'])->name('employer.profile.edit');
        Route::patch('employer/profile', [EmployerProfileController::class, 'update'])->name('employer.profile.update');
    });

    // KYC (any authenticated user)
    Route::get('kyc', [KycController::class, 'show'])->name('kyc.show');
    Route::post('kyc', [KycController::class, 'store'])->name('kyc.store');

    // Notifications (any authenticated user)
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');

    // Reviews (either party of an accepted application)
    Route::post('applications/{application}/review', [ReviewController::class, 'store'])->name('reviews.store');

    // Worker: applying to jobs, saved jobs
    Route::middleware('role:worker')->group(function () {
        // In-app (sidebar) job browsing for logged-in workers.
        Route::get('worker/jobs', [WorkerJobController::class, 'index'])->name('worker.jobs.index');
        Route::get('worker/jobs/{job}', [WorkerJobController::class, 'show'])->name('worker.jobs.show');

        Route::get('worker/applications', [JobApplicationController::class, 'index'])->name('applications.index');
        Route::post('jobs/{job}/apply', [JobApplicationController::class, 'store'])->name('applications.store');
        Route::delete('applications/{application}', [JobApplicationController::class, 'withdraw'])->name('applications.withdraw');

        Route::get('worker/saved', [SavedJobController::class, 'index'])->name('saved.index');
        Route::post('jobs/{job}/save', [SavedJobController::class, 'toggle'])->name('saved.toggle');
    });

    // Employer job management
    Route::middleware('role:employer')->group(function () {
        Route::get('employer/jobs', [JobListingController::class, 'index'])->name('jobs.index');
        // Posting a new job requires an active subscription.
        Route::middleware('subscription')->group(function () {
            Route::get('employer/jobs/create', [JobListingController::class, 'create'])->name('jobs.create');
            Route::post('employer/jobs', [JobListingController::class, 'store'])->name('jobs.store');
        });
        Route::get('employer/jobs/{job}/edit', [JobListingController::class, 'edit'])->name('jobs.edit');
        Route::patch('employer/jobs/{job}', [JobListingController::class, 'update'])->name('jobs.update');
        Route::delete('employer/jobs/{job}', [JobListingController::class, 'destroy'])->name('jobs.destroy');

        // Applicants for a job
        Route::get('employer/jobs/{job}/applicants', [ApplicantController::class, 'index'])->name('applicants.index');
        Route::patch('employer/applications/{application}', [ApplicantController::class, 'updateStatus'])->name('applicants.status');
        Route::post('employer/applications/{application}/unlock', [ApplicantController::class, 'unlockContact'])->name('applicants.unlock');

        // Shortlist
        Route::get('employer/shortlisted', [ApplicantController::class, 'shortlisted'])->name('applicants.shortlisted');
        Route::post('employer/applications/{application}/shortlist', [ApplicantController::class, 'toggleShortlist'])->name('applicants.shortlist');

        // Escrow payments (employer funds → holds → releases to worker)
        Route::post('employer/applications/{application}/escrow', [EscrowController::class, 'fund'])->name('escrow.fund');
        Route::post('employer/escrows/{escrow}/callback', [EscrowController::class, 'callback'])->name('escrow.callback');
        Route::post('employer/escrows/{escrow}/release', [EscrowController::class, 'release'])->name('escrow.release');

        // Team role management (owner only)
        Route::get('employer/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('employer/team', [TeamController::class, 'store'])->name('team.store');
        Route::patch('employer/team/{member}', [TeamController::class, 'update'])->name('team.update');
        Route::delete('employer/team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');

        // Worker directory (Typesense)
        Route::get('employer/workers', [WorkerDirectoryController::class, 'index'])->name('workers.index');
        Route::get('employer/workers/{worker}', [WorkerDirectoryController::class, 'show'])->name('workers.show');

        // Subscriptions
        Route::get('subscription', [SubscriptionController::class, 'pricing'])->name('subscription.pricing');
        Route::post('subscription/{plan}/subscribe', [SubscriptionController::class, 'subscribe'])->name('subscription.subscribe');
        Route::post('subscription/callback', [SubscriptionController::class, 'callback'])->name('subscription.callback');
        Route::get('subscription/{subscription}/invoice', [InvoiceController::class, 'show'])->name('subscription.invoice');
    });
});

// Razorpay webhook (no auth, CSRF-excluded)
Route::post('razorpay/webhook', [RazorpayWebhookController::class, 'handle'])->name('razorpay.webhook');

// Admin area
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Overview / analytics
    Route::get('overview', [AdminDashboardController::class, 'index'])->name('overview');

    // Separate employer / karigar directories (location-filterable)
    Route::get('employers', [AdminDirectoryController::class, 'employers'])->name('employers.index');
    Route::get('karigars', [AdminDirectoryController::class, 'karigars'])->name('karigars.index');

    // Reports with data filters + CSV export
    Route::get('reports', [AdminReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [AdminReportController::class, 'export'])->name('reports.export');

    // User management (suspend / reinstate)
    Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('users/{user}/suspend', [AdminUserController::class, 'suspend'])->name('users.suspend');
    Route::post('users/{user}/unsuspend', [AdminUserController::class, 'unsuspend'])->name('users.unsuspend');
    Route::post('users/{user}/quota', [AdminUserController::class, 'updateQuota'])->name('users.quota');
    Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');

    // Subscription plans & limits (incl. worker-database contact quota)
    Route::get('plans', [AdminPlanController::class, 'index'])->name('plans.index');
    Route::patch('plans/{plan}', [AdminPlanController::class, 'update'])->name('plans.update');

    // Job moderation
    Route::get('jobs', [AdminJobModerationController::class, 'index'])->name('jobs.index');
    Route::post('jobs/{job}/toggle', [AdminJobModerationController::class, 'toggle'])->name('jobs.toggle');

    // Escrow oversight & payouts
    Route::get('escrows', [AdminEscrowController::class, 'index'])->name('escrows.index');
    Route::post('escrows/{escrow}/release', [AdminEscrowController::class, 'release'])->name('escrows.release');
    Route::post('escrows/{escrow}/refund', [AdminEscrowController::class, 'refund'])->name('escrows.refund');

    Route::get('kyc', [AdminKycController::class, 'index'])->name('kyc.index');
    Route::post('kyc/{kyc}/approve', [AdminKycController::class, 'approve'])->name('kyc.approve');
    Route::post('kyc/{kyc}/reject', [AdminKycController::class, 'reject'])->name('kyc.reject');
    Route::get('kyc/{kyc}/document/{type}', [AdminKycController::class, 'document'])->name('kyc.document');

    // Discount coupons for subscriptions
    Route::get('coupons', [AdminCouponController::class, 'index'])->name('coupons.index');
    Route::post('coupons', [AdminCouponController::class, 'store'])->name('coupons.store');
    Route::patch('coupons/{coupon}', [AdminCouponController::class, 'update'])->name('coupons.update');
    Route::delete('coupons/{coupon}', [AdminCouponController::class, 'destroy'])->name('coupons.destroy');

    // Transactional email templates (admin-editable content for automated emails)
    Route::get('email-templates', [AdminEmailTemplateController::class, 'index'])->name('email-templates.index');
    Route::patch('email-templates/{emailTemplate}', [AdminEmailTemplateController::class, 'update'])->name('email-templates.update');
    Route::post('email-templates/{emailTemplate}/test', [AdminEmailTemplateController::class, 'test'])->name('email-templates.test');

    // Job categories (master list)
    Route::get('categories', [AdminCategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [AdminCategoryController::class, 'store'])->name('categories.store');
    Route::patch('categories/{category}', [AdminCategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [AdminCategoryController::class, 'destroy'])->name('categories.destroy');

    // App-wide settings (feature toggles)
    Route::get('settings', [AdminSettingController::class, 'index'])->name('settings.index');
    Route::patch('settings', [AdminSettingController::class, 'update'])->name('settings.update');
});

require __DIR__.'/settings.php';
