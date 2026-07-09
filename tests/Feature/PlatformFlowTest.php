<?php

use App\Enums\JobStatus;
use App\Enums\KycStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Ramesh Worker']);
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'name' => 'Suresh Employer']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin->value, 'name' => 'Admin']);

    $this->plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'price' => 499,
        'currency' => 'INR',
        'interval' => 'monthly',
        'features' => ['job_post_limit' => 10, 'contact_unlock_limit' => 50, 'featured' => true],
        'is_active' => true,
    ]);
});

function giveActiveSubscription(User $employer, Plan $plan): Subscription
{
    return Subscription::create([
        'employer_id' => $employer->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value,
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);
}

// ───────────────────────── PUBLIC ─────────────────────────

it('renders the landing page', function () {
    $this->get('/')->assertOk();
});

it('routes role login pages to OTP login (email kept for admin)', function () {
    $this->get('/worker/login')->assertRedirect('/worker/otp-login');
    $this->get('/employer/login')->assertRedirect('/employer/otp-login');
    $this->get('/admin/login')->assertOk();
});

it('shows an active job publicly and 404s a draft', function () {
    $active = $this->employer->jobListings()->create([
        'title' => 'Plumber needed', 'description' => 'Fix pipes', 'status' => JobStatus::Active->value, 'vacancies' => 2,
    ]);
    $draft = $this->employer->jobListings()->create([
        'title' => 'Secret draft', 'description' => 'Not public', 'status' => JobStatus::Draft->value, 'vacancies' => 1,
    ]);

    $this->get("/jobs/{$active->id}")->assertOk();
    $this->get("/jobs/{$draft->id}")->assertNotFound();
});

// ───────────────────────── WORKER ─────────────────────────

it('lets a worker view and update profile with an avatar', function () {
    Storage::fake('public');

    $this->actingAs($this->worker)->get('/worker/profile')->assertOk();

    $this->actingAs($this->worker)->patch('/worker/profile', [
        'phone' => '+919876543210',
        'skills' => ['Plumbing', 'Electrician'],
        'experience_years' => 5,
        'expected_wage' => 800,
        'wage_type' => 'daily',
        'bio' => 'Experienced worker',
        'city' => 'Jaipur',
        'state' => 'Rajasthan',
        'available' => true,
        'avatar' => UploadedFile::fake()->image('me.jpg'),
    ])->assertRedirect(route('worker.profile.edit'));

    $profile = $this->worker->workerProfile()->first();
    expect($profile->city)->toBe('Jaipur')
        ->and($profile->skills)->toBe(['Plumbing', 'Electrician'])
        ->and($profile->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($profile->avatar_path);
});

it('blocks an employer from the worker profile route', function () {
    $this->actingAs($this->employer)->get('/worker/profile')->assertForbidden();
});

// ───────────────────────── KYC ─────────────────────────

it('lets a worker submit KYC and stores it encrypted & pending', function () {
    Storage::fake('local');

    $this->actingAs($this->worker)->get('/kyc')->assertOk();

    $this->actingAs($this->worker)->post('/kyc', [
        'pan_number' => 'ABCDE1234F',
        'aadhaar_number' => '123412341234',
        'pan_doc' => UploadedFile::fake()->image('pan.jpg'),
        'aadhaar_doc' => UploadedFile::fake()->image('aadhaar.jpg'),
    ])->assertRedirect(route('kyc.show'));

    $kyc = $this->worker->kyc()->first();
    expect($kyc)->not->toBeNull()
        ->and($kyc->status)->toBe(KycStatus::Pending)
        ->and($kyc->pan_number)->toBe('ABCDE1234F') // decrypted via cast
        ->and($kyc->masked_pan)->toBe('ABXXXXXF');
    Storage::disk('local')->assertExists($kyc->pan_doc_path);
});

it('rejects invalid KYC input', function () {
    $this->actingAs($this->worker)->post('/kyc', [
        'pan_number' => 'bad',
        'aadhaar_number' => '123',
    ])->assertSessionHasErrors(['pan_number', 'aadhaar_number']);
});

// ───────────────────────── EMPLOYER ─────────────────────────

it('lets an employer update the company profile with a logo', function () {
    Storage::fake('public');

    $this->actingAs($this->employer)->get('/employer/profile')->assertOk();

    $this->actingAs($this->employer)->patch('/employer/profile', [
        'company_name' => 'Suresh Builders',
        'phone' => '+911234567890',
        'city' => 'Pune',
        'state' => 'Maharashtra',
        'about' => 'We build things',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ])->assertRedirect(route('employer.profile.edit'));

    $profile = $this->employer->employerProfile()->first();
    expect($profile->company_name)->toBe('Suresh Builders')
        ->and($profile->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($profile->logo_path);
});

it('shows the subscription pricing page', function () {
    $this->actingAs($this->employer)->get('/subscription')->assertOk();
});

it('redirects job posting to pricing without an active subscription', function () {
    $this->actingAs($this->employer)->get('/employer/jobs/create')
        ->assertRedirect(route('subscription.pricing'));
});

it('lets a subscribed employer post, edit and delete a job', function () {
    giveActiveSubscription($this->employer, $this->plan);

    // Create form reachable
    $this->actingAs($this->employer)->get('/employer/jobs/create')->assertOk();

    // Store
    $this->actingAs($this->employer)->post('/employer/jobs', [
        'title' => 'Carpenter for furniture',
        'description' => 'Build a wardrobe',
        'category' => 'Carpenter',
        'skills' => ['Woodwork'],
        'wage_min' => 500,
        'wage_max' => 900,
        'wage_type' => 'daily',
        'city' => 'Pune',
        'state' => 'Maharashtra',
        'vacancies' => 3,
        'status' => 'active',
    ])->assertRedirect(route('jobs.index'));

    $job = JobListing::firstWhere('title', 'Carpenter for furniture');
    expect($job)->not->toBeNull()
        ->and($job->employer_id)->toBe($this->employer->id)
        ->and($job->status)->toBe(JobStatus::Active);

    // My Jobs list shows it
    $this->actingAs($this->employer)->get('/employer/jobs')->assertOk();

    // Update
    $this->actingAs($this->employer)->patch("/employer/jobs/{$job->id}", [
        'title' => 'Carpenter — updated',
        'description' => 'Build a wardrobe and a table',
        'vacancies' => 5,
        'status' => 'active',
    ])->assertRedirect(route('jobs.index'));
    expect($job->fresh()->title)->toBe('Carpenter — updated');

    // Delete
    $this->actingAs($this->employer)->delete("/employer/jobs/{$job->id}")
        ->assertRedirect(route('jobs.index'));
    expect(JobListing::find($job->id))->toBeNull();
});

it('enforces the plan job-post limit', function () {
    $limited = Plan::create([
        'name' => 'Starter', 'slug' => 'starter', 'price' => 99, 'currency' => 'INR',
        'interval' => 'monthly', 'features' => ['job_post_limit' => 1], 'is_active' => true,
    ]);
    Subscription::create([
        'employer_id' => $this->employer->id, 'plan_id' => $limited->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);

    $this->employer->jobListings()->create([
        'title' => 'First', 'description' => 'x', 'status' => JobStatus::Active->value, 'vacancies' => 1,
    ]);

    $this->actingAs($this->employer)->post('/employer/jobs', [
        'title' => 'Second', 'description' => 'y', 'vacancies' => 1, 'status' => 'active',
    ])->assertSessionHas('toast');

    expect($this->employer->jobListings()->count())->toBe(1);
});

it('stops a worker from posting jobs', function () {
    $this->actingAs($this->worker)->get('/employer/jobs')->assertForbidden();
});

// ───────────────────────── ADMIN ─────────────────────────

it('lets an admin review and approve/reject KYC', function () {
    $kyc = $this->worker->kyc()->create([
        'pan_number' => 'ABCDE1234F',
        'aadhaar_number' => '123412341234',
        'aadhaar_hash' => hash('sha256', '123412341234'),
        'status' => KycStatus::Pending->value,
    ]);

    $this->actingAs($this->admin)->get('/admin/kyc')->assertOk();

    // Approve
    $this->actingAs($this->admin)->post("/admin/kyc/{$kyc->id}/approve")->assertRedirect();
    expect($kyc->fresh()->status)->toBe(KycStatus::Verified)
        ->and($kyc->fresh()->reviewed_by)->toBe($this->admin->id);

    // Reject requires remarks
    $this->actingAs($this->admin)->post("/admin/kyc/{$kyc->id}/reject", [])
        ->assertSessionHasErrors('remarks');
    $this->actingAs($this->admin)->post("/admin/kyc/{$kyc->id}/reject", ['remarks' => 'Blurry document'])
        ->assertRedirect();
    expect($kyc->fresh()->status)->toBe(KycStatus::Rejected)
        ->and($kyc->fresh()->remarks)->toBe('Blurry document');
});

it('blocks non-admins from the admin area', function () {
    $this->actingAs($this->worker)->get('/admin/kyc')->assertForbidden();
    $this->actingAs($this->employer)->get('/admin/kyc')->assertForbidden();
});
