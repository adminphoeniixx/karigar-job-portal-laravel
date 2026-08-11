<?php

use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A job's street address. City + state alone only ever put the map pin on the
 * city centre; the address is the spot a worker actually travels to, so it has
 * to survive the round trip and reach both the web page and the app.
 */
beforeEach(function () {
    config(['scout.driver' => null]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Test Employer']);

    // Posting is gated on an active subscription.
    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => 499, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 10, 'contact_unlock_limit' => 50], 'is_active' => true,
    ]);
    Subscription::create([
        'employer_id' => $this->employer->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value,
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);
});

function postJob(array $overrides = []): array
{
    return array_merge([
        'title' => 'Electrician for a showroom',
        'description' => 'Wiring and fittings for a new showroom floor.',
        'category' => 'Electrician',
        'city' => 'Kanpur',
        'state' => 'Uttar Pradesh',
        'vacancies' => 2,
        'status' => 'active',
        'contact_mode' => 'apply',
        'requires_worker_fee' => false,
    ], $overrides);
}

it('saves the address an employer typed', function () {
    $this->actingAs($this->employer)
        ->post('/employer/jobs', postJob([
            'address' => 'Shop 14, The Mall Road, Civil Lines',
            'latitude' => 26.4729912,
            'longitude' => 80.3516149,
        ]))
        ->assertRedirect();

    $job = JobListing::firstWhere('title', 'Electrician for a showroom');

    expect($job->address)->toBe('Shop 14, The Mall Road, Civil Lines')
        ->and((float) $job->latitude)->toBe(26.4729912)
        ->and($job->status)->toBe(JobStatus::Active);
});

it('lets a job stay address-less', function () {
    $this->actingAs($this->employer)->post('/employer/jobs', postJob())->assertRedirect();

    expect(JobListing::firstWhere('title', 'Electrician for a showroom')->address)->toBeNull();
});

it('rejects an address longer than the column', function () {
    $this->actingAs($this->employer)
        ->post('/employer/jobs', postJob(['address' => str_repeat('a', 256)]))
        ->assertSessionHasErrors('address');
});

it('shows the address on the public job page', function () {
    $job = $this->employer->jobListings()->create([
        'title' => 'Mason needed',
        'description' => 'Brickwork on a boundary wall.',
        'address' => 'Plot 7, Mall Road',
        'city' => 'Kanpur',
        'state' => 'Uttar Pradesh',
        'vacancies' => 1,
        'contact_mode' => 'apply',
        'requires_worker_fee' => false,
        'status' => JobStatus::Active,
    ]);

    $this->get("/jobs/{$job->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('job.address', 'Plot 7, Mall Road')->etc());
});

it('hands the address to the worker app, folded into the location label', function () {
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $job = $this->employer->jobListings()->create([
        'title' => 'Plumber needed',
        'description' => 'Bathroom fittings.',
        'address' => 'Plot 7, Mall Road',
        'city' => 'Kanpur',
        'state' => 'Uttar Pradesh',
        'vacancies' => 1,
        'contact_mode' => 'apply',
        'requires_worker_fee' => false,
        'status' => JobStatus::Active,
    ]);

    $this->actingAs($worker, 'sanctum')->getJson("/api/v1/jobs/{$job->id}")
        ->assertOk()
        ->assertJsonPath('data.address', 'Plot 7, Mall Road')
        ->assertJsonPath('data.location_label', 'Plot 7, Mall Road, Kanpur, Uttar Pradesh');
});
