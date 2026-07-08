<?php

use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => 499, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 10, 'contact_unlock_limit' => 50],
        'is_active' => true,
    ]);

    Subscription::create([
        'employer_id' => $this->employer->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);

    $this->payload = [
        'title' => 'Plumber needed', 'description' => 'Fix pipes',
        'vacancies' => 1, 'status' => 'active',
    ];
});

it('posts a direct-call job with a phone number', function () {
    $this->actingAs($this->employer)
        ->post('/employer/jobs', $this->payload + ['contact_mode' => 'call', 'contact_phone' => '+91 98765 43210'])
        ->assertRedirect('/employer/jobs');

    $job = $this->employer->jobListings()->first();
    expect($job->contact_mode)->toBe('call')
        ->and($job->contact_phone)->toBe('+91 98765 43210');
});

it('requires a phone number for call and both modes', function (string $mode) {
    $this->actingAs($this->employer)
        ->post('/employer/jobs', $this->payload + ['contact_mode' => $mode, 'contact_phone' => ''])
        ->assertSessionHasErrors('contact_phone');
})->with(['call', 'both']);

it('defaults to apply mode when no contact mode is sent', function () {
    $this->actingAs($this->employer)
        ->post('/employer/jobs', $this->payload)
        ->assertRedirect('/employer/jobs');

    expect($this->employer->jobListings()->first()->contact_mode)->toBe('apply');
});

it('shows the phone number on the public job page', function () {
    $job = $this->employer->jobListings()->create([
        'title' => 'Electrician', 'description' => 'Wiring work',
        'status' => JobStatus::Active->value, 'vacancies' => 1,
        'contact_mode' => 'both', 'contact_phone' => '+91 91234 56789',
    ]);

    $this->get("/jobs/{$job->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('jobs/Show')
            ->where('job.contact_mode', 'both')
            ->where('job.contact_phone', '+91 91234 56789'));
});
