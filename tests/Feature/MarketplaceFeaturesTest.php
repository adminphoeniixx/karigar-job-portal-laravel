<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\Plan;
use App\Models\Review;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Ramesh Worker']);
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'name' => 'Suresh Employer']);

    $this->plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => 499, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 10, 'contact_unlock_limit' => 50, 'featured' => true],
        'is_active' => true,
    ]);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Plumber needed', 'description' => 'Fix pipes', 'category' => 'Plumbing',
        'status' => JobStatus::Active->value, 'vacancies' => 2, 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ]);
});

function subscribe(User $employer, Plan $plan): Subscription
{
    return Subscription::create([
        'employer_id' => $employer->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);
}

// ───────────────────────── APPLICATIONS ─────────────────────────

it('lets a worker apply to a job and notifies the employer', function () {
    $this->actingAs($this->worker)
        ->post("/jobs/{$this->job->id}/apply", ['cover_note' => 'I can fix it', 'expected_wage' => 800])
        ->assertRedirect();

    $application = JobApplication::first();
    expect($application)->not->toBeNull()
        ->and($application->worker_id)->toBe($this->worker->id)
        ->and($application->status)->toBe(ApplicationStatus::Pending);

    // Employer received a database notification.
    expect($this->employer->notifications()->count())->toBe(1)
        ->and($this->employer->notifications()->first()->data['type'])->toBe('application.received');
});

it('blocks a duplicate application to the same job', function () {
    $this->job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending->value]);

    $this->actingAs($this->worker)->post("/jobs/{$this->job->id}/apply")->assertRedirect();

    expect(JobApplication::where('worker_id', $this->worker->id)->count())->toBe(1);
});

it('forbids an employer from applying to a job', function () {
    $this->actingAs($this->employer)->post("/jobs/{$this->job->id}/apply")->assertForbidden();
});

it('lets a worker see their applications and withdraw one', function () {
    $app = $this->job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending->value]);

    $this->actingAs($this->worker)->get('/worker/applications')->assertOk();

    $this->actingAs($this->worker)->delete("/applications/{$app->id}")->assertRedirect();
    expect($app->fresh()->status)->toBe(ApplicationStatus::Withdrawn);
});

// ───────────────────────── EMPLOYER APPLICANT REVIEW ─────────────────────────

it('lets an employer view applicants and accept one, notifying the worker', function () {
    $app = $this->job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending->value]);

    $this->actingAs($this->employer)->get("/employer/jobs/{$this->job->id}/applicants")->assertOk();

    $this->actingAs($this->employer)->patch("/employer/applications/{$app->id}", ['status' => 'accepted'])->assertRedirect();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Accepted)
        ->and($this->worker->notifications()->count())->toBe(1)
        ->and($this->worker->notifications()->first()->data['status'])->toBe('accepted');
});

it('forbids an employer from touching another employer\'s applicants', function () {
    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $app = $this->job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending->value]);

    $this->actingAs($other)->get("/employer/jobs/{$this->job->id}/applicants")->assertForbidden();
    $this->actingAs($other)->patch("/employer/applications/{$app->id}", ['status' => 'accepted'])->assertForbidden();
});

// ───────────────────────── CONTACT UNLOCK (plan-gated) ─────────────────────────

it('lets a subscribed employer unlock a contact', function () {
    subscribe($this->employer, $this->plan);
    $app = $this->job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending->value]);

    $this->actingAs($this->employer)->post("/employer/applications/{$app->id}/unlock")->assertRedirect();
    expect($app->fresh()->contact_unlocked)->toBeTrue();
});

it('enforces the plan contact unlock limit', function () {
    // Plan that allows exactly one unlock.
    $limited = Plan::create([
        'name' => 'Solo', 'slug' => 'solo', 'price' => 99, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 5, 'contact_unlock_limit' => 1], 'is_active' => true,
    ]);
    subscribe($this->employer, $limited);

    $w2 = User::factory()->create(['role' => UserRole::Worker->value]);
    $a1 = $this->job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending->value]);
    $a2 = $this->job->applications()->create(['worker_id' => $w2->id, 'status' => ApplicationStatus::Pending->value]);

    $this->actingAs($this->employer)->post("/employer/applications/{$a1->id}/unlock");
    $this->actingAs($this->employer)->post("/employer/applications/{$a2->id}/unlock");

    expect($a1->fresh()->contact_unlocked)->toBeTrue()
        ->and($a2->fresh()->contact_unlocked)->toBeFalse(); // blocked by limit
});

// ───────────────────────── SAVED JOBS ─────────────────────────

it('lets a worker save and unsave a job', function () {
    $this->actingAs($this->worker)->post("/jobs/{$this->job->id}/save")->assertRedirect();
    expect($this->worker->savedJobs()->count())->toBe(1);

    $this->actingAs($this->worker)->get('/worker/saved')->assertOk();

    $this->actingAs($this->worker)->post("/jobs/{$this->job->id}/save")->assertRedirect();
    expect($this->worker->savedJobs()->count())->toBe(0); // toggled off
});

// ───────────────────────── REVIEWS ─────────────────────────

it('lets both parties review after an accepted application', function () {
    $app = $this->job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Accepted->value]);

    // Employer reviews the worker.
    $this->actingAs($this->employer)->post("/applications/{$app->id}/review", ['rating' => 5, 'comment' => 'Great work'])->assertRedirect();
    // Worker reviews the employer.
    $this->actingAs($this->worker)->post("/applications/{$app->id}/review", ['rating' => 4, 'comment' => 'Paid on time'])->assertRedirect();

    expect(Review::count())->toBe(2)
        ->and($this->worker->fresh()->averageRating())->toBe(5.0)
        ->and($this->employer->fresh()->averageRating())->toBe(4.0);
});

it('forbids reviewing a not-yet-accepted application', function () {
    $app = $this->job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending->value]);

    $this->actingAs($this->employer)->post("/applications/{$app->id}/review", ['rating' => 5])->assertForbidden();
    expect(Review::count())->toBe(0);
});

// ───────────────────────── WORKER DIRECTORY ─────────────────────────

it('lets an employer open the worker directory and a worker profile', function () {
    $profile = $this->worker->workerProfile()->create([
        'skills' => ['Plumbing'], 'city' => 'Jaipur', 'state' => 'Rajasthan', 'available' => true, 'phone' => '9876543210',
    ]);

    $this->actingAs($this->employer)->get('/employer/workers')->assertOk();
    $this->actingAs($this->employer)->get("/employer/workers/{$profile->id}")->assertOk();
});

// ───────────────────────── NOTIFICATIONS ─────────────────────────

it('lists notifications and marks them read', function () {
    // Generate one by applying.
    $this->actingAs($this->worker)->post("/jobs/{$this->job->id}/apply");
    expect($this->employer->unreadNotifications()->count())->toBe(1);

    $this->actingAs($this->employer)->get('/notifications')->assertOk();

    $this->actingAs($this->employer)->post('/notifications/read-all')->assertRedirect();
    expect($this->employer->fresh()->unreadNotifications()->count())->toBe(0);
});

// ───────────────────────── APPLY AUTH-GATE ─────────────────────────

it('sends a guest to OTP login and returns them to the job after auth', function () {
    // Guest opening the login page with a redirect target stashes it as
    // intended, then gets routed on to the OTP flow.
    $this->get("/worker/login?redirect=/jobs/{$this->job->id}")->assertRedirect('/worker/otp-login');
    expect(session('url.intended'))->toBe("/jobs/{$this->job->id}");

    // After the worker verifies their OTP, they are returned there.
    $this->worker->update(['phone' => '9876512345']);
    $this->post('/otp/send', ['phone' => '9876512345']);

    $this->post('/worker/otp/verify', [
        'phone' => '9876512345',
        'otp' => Cache::get('phone_otp.9876512345'),
    ])->assertRedirect("/jobs/{$this->job->id}");
});

it('ignores an unsafe (off-site) redirect target', function () {
    $this->get('/worker/login?redirect=https://evil.com')->assertRedirect('/worker/otp-login');
    expect(session('url.intended'))->toBeNull();
});

it('shows the application status on the public job page for a worker who applied', function () {
    $this->job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Accepted->value]);

    $this->actingAs($this->worker)
        ->get("/jobs/{$this->job->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('application.status', 'accepted')
            ->where('canApply', true));
});

// ───────────────────────── IN-APP WORKER JOB PAGES ─────────────────────────

it('lets a worker browse jobs inside their panel (with sidebar layout)', function () {
    $this->actingAs($this->worker)->get('/worker/jobs')->assertOk();
});

it('shows an in-app job detail with the worker application status', function () {
    $this->job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending->value]);

    $this->actingAs($this->worker)
        ->get("/worker/jobs/{$this->job->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('worker/JobShow')->where('application.status', 'pending'));
});

it('forbids a non-worker from the in-app worker jobs page', function () {
    $this->actingAs($this->employer)->get('/worker/jobs')->assertForbidden();
});

// ───────────────────────── NEW-JOB NOTIFICATIONS ─────────────────────────

it('notifies workers when an employer posts a new active job', function () {
    subscribe($this->employer, $this->plan);
    $this->worker->workerProfile()->create(['skills' => ['Plumbing'], 'city' => 'Jaipur', 'available' => true]);
    $otherWorker = User::factory()->create(['role' => UserRole::Worker->value]);
    $otherWorker->workerProfile()->create(['skills' => ['Welding'], 'city' => 'Pune', 'available' => true]);

    $this->actingAs($this->employer)->post('/employer/jobs', [
        'title' => 'Pipeline plumber', 'description' => 'Fix a pipeline', 'category' => 'Plumbing',
        'skills' => ['Plumbing'], 'city' => 'Jaipur', 'state' => 'Rajasthan', 'vacancies' => 1, 'status' => 'active',
    ])->assertRedirect(route('jobs.index'));

    // Matching worker (Jaipur + Plumbing) gets notified.
    expect($this->worker->fresh()->notifications()->count())->toBe(1)
        ->and($this->worker->fresh()->notifications()->first()->data['type'])->toBe('job.posted');
});
