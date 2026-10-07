<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Escrow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Escrow takes money in through Razorpay and pays it out through RazorpayX.
 * Until payouts are configured it must not take money it cannot pay out.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'scout.driver' => 'null',
        'services.razorpay.key' => 'rzp_test_key',
        'services.razorpay.secret' => 'secret',
        'services.razorpayx.account_number' => null,
    ]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $job = $this->employer->jobListings()->create([
        'title' => 'Tailor', 'description' => 'Stitching', 'status' => JobStatus::Active->value, 'vacancies' => 1,
    ]);
    $this->application = $job->applications()->create([
        'worker_id' => $worker->id, 'status' => ApplicationStatus::Accepted,
    ]);
});

it('takes no escrow money while payouts are not configured', function () {
    $this->actingAs($this->employer)
        ->from("/employer/jobs/{$this->application->job_listing_id}/applicants")
        ->post("/employer/applications/{$this->application->id}/escrow", ['amount' => 5000])
        ->assertRedirect()
        ->assertSessionHas('error', 'Escrow payments are not available yet.');

    expect(Escrow::count())->toBe(0);

    $this->actingAs($this->employer)
        ->get("/employer/jobs/{$this->application->job_listing_id}/applicants")
        ->assertInertia(fn ($page) => $page->where('escrowEnabled', false));
});
