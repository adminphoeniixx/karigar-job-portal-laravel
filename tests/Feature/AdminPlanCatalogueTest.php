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

    $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);

    $this->plan = Plan::create([
        'name' => '3 Months', 'slug' => 'job-3-months', 'type' => Plan::TYPE_JOB, 'price' => 3649, 'mrp' => 4194,
        'currency' => 'INR', 'interval' => Plan::INTERVAL_DAYS, 'duration_days' => 90, 'job_live_days' => 15,
        'razorpay_plan_id' => 'plan_old', 'razorpay_amount' => 4305.82,
        'features' => ['job_post_limit' => 6, 'contact_unlock_limit' => 175, 'contact_database_limit' => 10000], 'is_active' => true,
    ]);
});

function planForm(array $overrides = []): array
{
    return [
        'name' => '1 Month', 'tagline' => 'Ideal for small teams', 'price' => 1949, 'mrp' => 2097,
        'duration_days' => 30, 'job_live_days' => 15, 'job_post_limit' => 3, 'contact_unlock_limit' => 75,
        'contact_database_limit' => 3000, 'featured' => false, 'is_active' => true, ...$overrides,
    ];
}

it('lets an admin add a plan with its own length, job life and MRP', function () {
    $this->actingAs($this->admin)->post('/admin/plans', planForm(['type' => 'job']))->assertRedirect();

    $plan = Plan::where('slug', '1-month')->first();

    expect($plan->durationDays())->toBe(30)
        ->and($plan->jobLiveDays())->toBe(15)
        ->and($plan->tagline)->toBe('Ideal for small teams')
        ->and($plan->discount_percent)->toBe(7)
        ->and($plan->featureList())->toContain('3 job posts, each live for 15 days');
});

it('refuses a plan shorter than Razorpay can bill', function () {
    $this->actingAs($this->admin)->post('/admin/plans', planForm(['type' => 'job', 'duration_days' => 5]))
        ->assertSessionHasErrors('duration_days');
});

it('needs a new Razorpay plan when the length changes, not when only the name does', function () {
    $this->actingAs($this->admin)->patch("/admin/plans/{$this->plan->id}", planForm(['name' => 'Quarter', 'duration_days' => 90, 'price' => 3649]));
    expect($this->plan->fresh()->razorpay_plan_id)->toBe('plan_old');

    $this->actingAs($this->admin)->patch("/admin/plans/{$this->plan->id}", planForm(['duration_days' => 120, 'price' => 3649]));
    expect($this->plan->fresh())->razorpay_plan_id->toBeNull()->duration_days->toBe(120);
});

it('deletes a plan nobody bought, and only hides one that was bought', function () {
    $this->actingAs($this->admin)->delete("/admin/plans/{$this->plan->id}")->assertRedirect();
    expect(Plan::find($this->plan->id))->toBeNull();

    $bought = Plan::create(['name' => 'Bought', 'slug' => 'bought', 'type' => Plan::TYPE_JOB, 'price' => 699, 'currency' => 'INR', 'interval' => Plan::INTERVAL_DAYS, 'duration_days' => 30, 'features' => [], 'is_active' => true]);
    Subscription::create(['employer_id' => $this->employer->id, 'plan_id' => $bought->id, 'status' => SubscriptionStatus::Expired->value]);

    $this->actingAs($this->admin)->delete("/admin/plans/{$bought->id}");
    expect(Plan::find($bought->id))->not->toBeNull();
});

it('keeps employers out of the plan admin', function () {
    $this->actingAs($this->employer)->post('/admin/plans', planForm(['type' => 'job']))->assertForbidden();
});

it('runs a 90-day plan for 90 days', function () {
    $subscription = Subscription::create(['employer_id' => $this->employer->id, 'plan_id' => $this->plan->id, 'status' => SubscriptionStatus::Created->value]);

    $subscription->activateWithInvoice();

    expect((int) round($subscription->starts_at->diffInDays($subscription->ends_at)))->toBe(90)
        ->and($this->plan->interval_label)->toBe('90 days');
});

it('ends a job after its plan\'s job-live days, keeping an earlier date the employer chose', function () {
    Subscription::create([
        'employer_id' => $this->employer->id, 'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addDays(90),
    ]);
    $job = fn (array $extra = []) => $this->employer->jobListings()->create([
        'title' => 'Mason', 'description' => 'Brick work', 'status' => JobStatus::Active->value, 'vacancies' => 1, ...$extra,
    ]);

    $open = $job(['expires_at' => now()->addDays(60)]);
    $short = $job(['expires_at' => now()->addDays(5)]);

    expect((int) round(now()->diffInDays($open->expires_at)))->toBe(15)
        ->and((int) round(now()->diffInDays($short->expires_at)))->toBe(5);

    // Editing the date later cannot stretch it past the plan either.
    $open->update(['expires_at' => now()->addDays(40)]);
    expect((int) round(now()->diffInDays($open->fresh()->expires_at)))->toBe(15);
});

it('sends the apps the MRP, discount and lengths', function () {
    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/plans')
        ->assertOk()
        ->assertJsonPath('plans.0.mrp', 4194)
        ->assertJsonPath('plans.0.discount_percent', 13)
        ->assertJsonPath('plans.0.duration_days', 90)
        ->assertJsonPath('plans.0.job_live_days', 15)
        ->assertJsonPath('plans.0.interval_label', '90 days');
});

it('shows the admin\'s own feature lines and badge in place of the built ones', function () {
    $this->actingAs($this->admin)->patch("/admin/plans/{$this->plan->id}", planForm([
        'featured' => true,
        'badge' => 'Best seller',
        'highlights' => ['  6 job posts in 90 days ', '', 'Priority support'],
    ]))->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();

    expect($plan->featureList())->toBe(['6 job posts in 90 days', 'Priority support'])
        ->and($plan->badgeLabel())->toBe('Best seller')
        ->and($plan->limitFeatureList())->toContain('3 job posts, each live for 15 days');

    // Emptied again: back to the lines built from the limits.
    $this->actingAs($this->admin)->patch("/admin/plans/{$this->plan->id}", planForm(['highlights' => []]));

    expect($this->plan->fresh()->featureList())->toContain('3 job posts, each live for 15 days')
        ->and($this->plan->fresh()->badgeLabel())->toBeNull();
});

it('lets an admin set the AI call credits a plan comes with', function () {
    $this->actingAs($this->admin)->patch("/admin/plans/{$this->plan->id}", planForm(['ai_call_credits' => 20]))->assertSessionHasNoErrors();

    expect($this->plan->fresh()->includedAiCredits())->toBe(20);
});
