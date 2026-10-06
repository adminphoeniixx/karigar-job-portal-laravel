<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Listeners\HoldAlertsForUnavailableWorkers;
use App\Models\JobApplication;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\JobInviteNotification;
use App\Notifications\NewJobNotification;
use App\Notifications\ShortlistedNotification;
use App\Services\Screening\ScreeningService;
use App\Services\Screening\StubVoiceAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'city' => 'Jaipur']);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'phone' => '9876500001']);
    $this->worker->workerProfile()->create(['skills' => ['Weaving'], 'city' => 'Jaipur', 'available' => false]);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Handloom weaver',
        'description' => 'Workshop work',
        'category' => 'Weaving',
        'skills' => ['Weaving'],
        'city' => 'Jaipur',
        'state' => 'Rajasthan',
        'vacancies' => 2,
        'contact_mode' => 'apply',
        'requires_worker_fee' => false,
        'status' => JobStatus::Active,
    ]);
});

it('keeps job alerts and invites away from a karigar who is not available', function () {
    $this->worker->notify(new NewJobNotification($this->job));
    $this->worker->notify(new JobInviteNotification($this->job));

    expect($this->worker->notifications()->count())->toBe(0);
});

it('keeps application updates in the in-app list but sends no push or email', function () {
    $application = JobApplication::create([
        'job_listing_id' => $this->job->id,
        'worker_id' => $this->worker->id,
        'status' => ApplicationStatus::Pending,
    ]);
    $notification = new ShortlistedNotification($application);
    $listener = new HoldAlertsForUnavailableWorkers;

    expect($listener->handle(new NotificationSending($this->worker, $notification, 'database')))->toBeNull()
        ->and($listener->handle(new NotificationSending($this->worker, $notification, FcmChannel::class)))->toBeFalse()
        ->and($this->worker->alertEmail())->toBeNull();

    $this->worker->notify($notification);
    expect($this->worker->notifications()->count())->toBe(1);
});

it('lets everything through again once the karigar is available', function () {
    $this->worker->workerProfile->update(['available' => true]);
    $worker = $this->worker->fresh();

    $worker->notify(new NewJobNotification($this->job));

    expect($worker->notifications()->count())->toBe(1)
        ->and($worker->alertEmail())->toBe($worker->email);
});

it('shows no jobs in the app and says why', function () {
    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs')
        ->assertOk()
        ->assertJsonPath('unavailable', true)
        ->assertJsonCount(0, 'data');

    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/worker/dashboard')
        ->assertOk()
        ->assertJsonCount(0, 'latest_jobs');
});

it('shows the switch instead of jobs on the website, and turns it back on', function () {
    $this->actingAs($this->worker)->get('/worker/jobs')
        ->assertInertia(fn ($page) => $page->where('unavailable', true)->where('jobs.data', []));

    $this->actingAs($this->worker)->patch('/worker/availability', ['available' => true])->assertRedirect();

    expect($this->worker->workerProfile->fresh()->available)->toBeTrue();
    $this->actingAs($this->worker->fresh())->get('/worker/jobs')
        ->assertInertia(fn ($page) => $page->where('unavailable', false));
});

it('refuses an invite to a karigar who is not available', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/jobs/{$this->job->id}/invite", ['worker_id' => $this->worker->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'worker_unavailable');
});

it('never rings a karigar who is not available', function () {
    config(['screening.from_number' => '+918031703250']);
    $application = JobApplication::create([
        'job_listing_id' => $this->job->id,
        'worker_id' => $this->worker->id,
        'status' => ApplicationStatus::Pending,
    ]);

    $screening = new ScreeningService(new StubVoiceAgent);

    expect($screening->blocker($application->fresh()))->toBe('worker_unavailable');
});
