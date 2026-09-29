<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkerContactUnlock;
use App\Services\CreditWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $this->other = User::factory()->create(['role' => UserRole::Worker->value]);

    $this->profile = $this->worker->workerProfile()->create(['phone' => '9876543210', 'city' => 'Jaipur', 'state' => 'Rajasthan']);

    // One unlock in the plan, so the shared pool is easy to exhaust.
    $this->plan = Plan::create([
        'name' => 'Solo', 'slug' => 'solo', 'price' => 99, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 5, 'contact_unlock_limit' => 1, 'contact_database_limit' => 1000],
        'is_active' => true,
    ]);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Weaver', 'description' => 'Handloom', 'category' => 'Weaving',
        'status' => JobStatus::Active->value, 'vacancies' => 2, 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ]);
});

function subscribeDirectory(User $employer, Plan $plan): void
{
    Subscription::create([
        'employer_id' => $employer->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);
}

it('keeps a directory karigar\'s number hidden until it is unlocked', function () {
    subscribeDirectory($this->employer, $this->plan);

    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/workers/{$this->profile->id}")
        ->assertOk()
        ->assertJsonPath('worker.phone', null)
        ->assertJsonPath('worker.can_unlock', true);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/workers/{$this->profile->id}/unlock")
        ->assertOk()
        ->assertJsonPath('worker.phone', '9876543210')
        ->assertJsonPath('credits.unlocks_used', 1);

    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/workers/{$this->profile->id}")
        ->assertJsonPath('worker.phone', '9876543210')
        ->assertJsonPath('worker.contact_unlocked', true);
});

it('spends directory unlocks from the same pool as applicant unlocks', function () {
    subscribeDirectory($this->employer, $this->plan);

    $this->actingAs($this->employer)->post("/employer/workers/{$this->profile->id}/unlock")->assertRedirect();

    $application = $this->job->applications()->create(['worker_id' => $this->other->id, 'status' => ApplicationStatus::Pending->value]);
    $this->actingAs($this->employer)->post("/employer/applications/{$application->id}/unlock");

    expect($application->fresh()->contact_unlocked)->toBeFalse();
});

it('does not charge again for a karigar already unlocked', function () {
    subscribeDirectory($this->employer, $this->plan);

    $this->actingAs($this->employer)->post("/employer/workers/{$this->profile->id}/unlock");

    // The same karigar applies later: their application opens for free.
    $application = $this->job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending->value]);
    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/applicants/{$application->id}/unlock")
        ->assertOk();

    // And unlocking them from the directory again is free too.
    $this->actingAs($this->employer)->post("/employer/workers/{$this->profile->id}/unlock");

    expect($application->fresh()->contact_unlocked)->toBeTrue()
        ->and(CreditWallet::for($this->employer)->unlocksUsed())->toBe(1)
        ->and(WorkerContactUnlock::count())->toBe(1);
});

it('refuses a directory unlock once the pool is spent', function () {
    subscribeDirectory($this->employer, $this->plan);
    $otherProfile = $this->other->workerProfile()->create(['phone' => '9000000099']);

    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/workers/{$this->profile->id}/unlock")->assertOk();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/workers/{$otherProfile->id}/unlock")
        ->assertStatus(422)
        ->assertJsonPath('code', 'out_of_credits');
});

it('refuses a directory unlock without a plan', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/workers/{$this->profile->id}/unlock")
        ->assertStatus(422)
        ->assertJsonPath('code', 'no_plan');

    expect(WorkerContactUnlock::count())->toBe(0);
});

it('lets the employer message a karigar unlocked from the directory', function () {
    subscribeDirectory($this->employer, $this->plan);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/conversations', ['worker_id' => $this->worker->id, 'body' => 'Namaste'])
        ->assertStatus(422);

    $this->actingAs($this->employer)->post("/employer/workers/{$this->profile->id}/unlock");

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/conversations', ['worker_id' => $this->worker->id, 'body' => 'Namaste'])
        ->assertCreated();
});
