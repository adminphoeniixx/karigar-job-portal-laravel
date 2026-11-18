<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The numbers on the dashboards and reports, and what a public job page
 * hands to the browser.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
});

function analyticsJob(User $employer, array $extra = []): JobListing
{
    return $employer->jobListings()->create([
        'title' => 'Tailor for boutique', 'description' => 'Stitching work', 'category' => 'Tailoring',
        'skills' => ['Stitching'], 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'vacancies' => 1,
        'wage_min' => 15000, 'wage_max' => 22000, 'contact_mode' => 'apply',
        'requires_worker_fee' => false, 'status' => JobStatus::Active, ...$extra,
    ]);
}

it('counts only plans that are still running as live, and normalises the run-rate to 30 days', function () {
    $monthly = Plan::create(['name' => 'Starter', 'slug' => 'starter', 'price' => 900, 'currency' => 'INR', 'interval' => 'days', 'duration_days' => 30, 'type' => Plan::TYPE_JOB, 'features' => [], 'is_active' => true]);
    $fortnight = Plan::create(['name' => 'Single', 'slug' => 'single', 'price' => 500, 'currency' => 'INR', 'interval' => 'days', 'duration_days' => 15, 'type' => Plan::TYPE_JOB, 'features' => [], 'is_active' => true]);

    $sub = fn (Plan $plan, $endsAt) => Subscription::forceCreate([
        'employer_id' => User::factory()->create(['role' => UserRole::Employer->value])->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now()->subDays(40), 'ends_at' => $endsAt,
    ]);
    $sub($monthly, now()->addDays(10));
    $sub($fortnight, now()->addDays(5));
    // Still "active" in the row, but the period ended: not a live plan.
    $sub($monthly, now()->subDays(3));

    $this->actingAs($this->admin)->get('/admin/overview')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.activeSubscriptions', 2)
            // 900 for 30 days + 500 for 15 days (= 1000 per 30).
            ->where('stats.mrr', 1900));
});

it('puts a signup on the Indian day it happened, not the UTC one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00', 'Asia/Kolkata'));
    // 01:00 IST on the 16th is still the 15th in UTC.
    User::factory()->create(['role' => UserRole::Worker->value, 'created_at' => CarbonImmutable::parse('2026-11-16 01:00', 'Asia/Kolkata')->utc()]);

    $this->actingAs($this->admin)->get('/admin/overview')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('signups.13.date', '2026-11-17')
            ->where('signups.12', ['date' => '2026-11-16', 'count' => 1])
            ->where('signups.11.count', 0));
});

it('filters report signups by state the same way the CSV does', function () {
    $inState = User::factory()->create(['role' => UserRole::Worker->value]);
    $inState->workerProfile()->create(['skills' => ['Stitching'], 'city' => 'Jaipur', 'state' => 'Rajasthan']);
    $elsewhere = User::factory()->create(['role' => UserRole::Worker->value]);
    $elsewhere->workerProfile()->create(['skills' => ['Stitching'], 'city' => 'Chennai', 'state' => 'Tamil Nadu']);

    $this->actingAs($this->admin)->get('/admin/reports?state=Rajasthan')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tiles.workers', 1)->has('tiles.hires')->has('tiles.aiCalls'));

    $csv = $this->actingAs($this->admin)->get('/admin/reports/export?type=workers&state=Rajasthan')->streamedContent();
    expect(substr_count(trim($csv), "\n"))->toBe(1);
});

it('reads the report range as Indian days and counts hires in it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00', 'Asia/Kolkata'));
    $job = analyticsJob($this->employer);
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    // Applied and hired at 00:30 IST on the 17th: 19:00 UTC on the 16th.
    $at = CarbonImmutable::parse('2026-11-17 00:30', 'Asia/Kolkata')->utc();
    JobApplication::forceCreate([
        'job_listing_id' => $job->id, 'worker_id' => $worker->id, 'status' => ApplicationStatus::Accepted->value,
        'status_changed_at' => $at, 'created_at' => $at, 'updated_at' => $at,
    ]);

    $this->actingAs($this->admin)->get('/admin/reports?from=2026-11-17&to=2026-11-17')
        ->assertInertia(fn ($page) => $page->where('tiles.applications', 1)->where('tiles.hires', 1)
            ->where('monthly.0.hires', 1));

    $this->actingAs($this->admin)->get('/admin/reports?from=2026-11-16&to=2026-11-16')
        ->assertInertia(fn ($page) => $page->where('tiles.applications', 0)->where('tiles.hires', 0));
});

it('gives the dashboard snapshot the applications by status, adding up to the total', function () {
    $job = analyticsJob($this->employer);
    foreach ([ApplicationStatus::Pending, ApplicationStatus::Pending, ApplicationStatus::Accepted, ApplicationStatus::Rejected] as $status) {
        JobApplication::create(['job_listing_id' => $job->id, 'worker_id' => User::factory()->create(['role' => UserRole::Worker->value])->id, 'status' => $status]);
    }

    $this->actingAs($this->employer)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('snapshot.total', 4)
            ->where('snapshot.segments', [
                ['label' => 'Pending', 'value' => 2],
                ['label' => 'Accepted', 'value' => 1],
                ['label' => 'Rejected', 'value' => 1],
                ['label' => 'Withdrawn', 'value' => 0],
            ])
            ->has('activity.Daily.labels', 14));
});

it('keeps the contact person and the moderation notes of an apply-only job off its public page', function () {
    $job = analyticsJob($this->employer, [
        'contact_phone' => '9876500099', 'contact_name' => 'Suresh', 'contact_designation' => 'Owner',
        'moderation_status' => 'approved', 'moderation_note' => 'Checked by hand',
    ]);

    $this->get("/jobs/{$job->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('job.title', 'Tailor for boutique')
            ->missing('job.contact_phone')->missing('job.contact_name')
            ->missing('job.moderation_note')->missing('job.moderation_status')
            ->missing('job.ai_call_enabled')->missing('job.views_count'));

    // A job that takes calls still shows who to ring.
    $callable = analyticsJob($this->employer, ['contact_mode' => 'call', 'contact_phone' => '9876500098', 'contact_name' => 'Meena']);

    $this->get("/jobs/{$callable->id}")
        ->assertInertia(fn ($page) => $page->where('job.contact_phone', '9876500098')->where('job.contact_name', 'Meena'));
});
