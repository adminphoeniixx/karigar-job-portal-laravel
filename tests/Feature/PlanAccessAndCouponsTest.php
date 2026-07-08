<?php

use App\Enums\DiscountType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Mail\TemplatedMail;
use App\Models\Coupon;
use App\Models\EmailTemplate;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\TemplatedMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'name' => 'Suresh Employer']);
    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Ramesh Worker']);

    $this->plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => 1000, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 10, 'contact_unlock_limit' => 50, 'contact_database_limit' => 2000, 'featured' => true],
        'is_active' => true,
    ]);
});

function activate(User $employer, Plan $plan): Subscription
{
    return Subscription::create([
        'employer_id' => $employer->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);
}

// ───────────────────────── COUPON MODEL LOGIC ─────────────────────────

it('computes a percentage discount with an optional cap', function () {
    $coupon = new Coupon(['discount_type' => DiscountType::Percent, 'discount_value' => 20, 'max_discount_amount' => null]);
    expect($coupon->discountFor(1000))->toBe(200.0);

    $capped = new Coupon(['discount_type' => DiscountType::Percent, 'discount_value' => 50, 'max_discount_amount' => 300]);
    expect($capped->discountFor(1000))->toBe(300.0);
});

it('computes a flat discount and never exceeds the price', function () {
    $coupon = new Coupon(['discount_type' => DiscountType::Flat, 'discount_value' => 250]);
    expect($coupon->discountFor(1000))->toBe(250.0);
    expect($coupon->discountFor(100))->toBe(100.0); // clamped to price
});

it('rejects an inactive, expired, or not-yet-started coupon', function () {
    $inactive = Coupon::create(['code' => 'OFF', 'discount_type' => DiscountType::Percent, 'discount_value' => 10, 'is_active' => false]);
    expect($inactive->reasonInvalidFor($this->employer, $this->plan, 1000))->not->toBeNull();

    $expired = Coupon::create(['code' => 'OLD', 'discount_type' => DiscountType::Percent, 'discount_value' => 10, 'is_active' => true, 'expires_at' => now()->subDay()]);
    expect($expired->reasonInvalidFor($this->employer, $this->plan, 1000))->not->toBeNull();

    $future = Coupon::create(['code' => 'SOON', 'discount_type' => DiscountType::Percent, 'discount_value' => 10, 'is_active' => true, 'starts_at' => now()->addDay()]);
    expect($future->reasonInvalidFor($this->employer, $this->plan, 1000))->not->toBeNull();
});

it('enforces plan eligibility, minimum amount, and usage limits', function () {
    $other = Plan::create(['name' => 'Basic', 'slug' => 'basic', 'price' => 500, 'interval' => 'monthly', 'features' => [], 'is_active' => true]);

    $planLimited = Coupon::create(['code' => 'PROONLY', 'discount_type' => DiscountType::Percent, 'discount_value' => 10, 'is_active' => true, 'plan_ids' => [$this->plan->id]]);
    expect($planLimited->reasonInvalidFor($this->employer, $this->plan, 1000))->toBeNull();
    expect($planLimited->reasonInvalidFor($this->employer, $other, 500))->not->toBeNull();

    $minAmount = Coupon::create(['code' => 'MIN', 'discount_type' => DiscountType::Percent, 'discount_value' => 10, 'is_active' => true, 'min_amount' => 800]);
    expect($minAmount->reasonInvalidFor($this->employer, $this->plan, 1000))->toBeNull();
    expect($minAmount->reasonInvalidFor($this->employer, $other, 500))->not->toBeNull();

    $used = Coupon::create(['code' => 'MAXED', 'discount_type' => DiscountType::Percent, 'discount_value' => 10, 'is_active' => true, 'max_redemptions' => 1, 'redeemed_count' => 1]);
    expect($used->reasonInvalidFor($this->employer, $this->plan, 1000))->not->toBeNull();
});

// ───────────────────────── COUPON ADMIN + PRICING ─────────────────────────

it('lets an admin create, update, and delete a coupon', function () {
    $this->actingAs($this->admin)
        ->post('/admin/coupons', [
            'code' => 'welcome20', 'discount_type' => 'percent', 'discount_value' => 20,
            'per_user_limit' => 1, 'is_active' => true,
        ])->assertRedirect();

    $coupon = Coupon::first();
    expect($coupon->code)->toBe('WELCOME20'); // stored uppercase

    $this->actingAs($this->admin)->patch("/admin/coupons/{$coupon->id}", [
        'code' => 'WELCOME20', 'discount_type' => 'percent', 'discount_value' => 30,
        'per_user_limit' => 2, 'is_active' => true,
    ])->assertRedirect();
    expect($coupon->fresh()->discount_value)->toBe('30.00');

    $this->actingAs($this->admin)->delete("/admin/coupons/{$coupon->id}")->assertRedirect();
    expect(Coupon::count())->toBe(0);
});

it('previews a valid coupon on the pricing page for an employer', function () {
    Coupon::create(['code' => 'SAVE10', 'discount_type' => DiscountType::Percent, 'discount_value' => 10, 'is_active' => true]);

    $this->actingAs($this->employer)
        ->get('/subscription?coupon=save10')
        ->assertInertia(fn ($page) => $page
            ->where('couponResult.valid', true)
            ->where('couponResult.code', 'SAVE10')
        );
});

it('flags an unknown coupon as invalid', function () {
    $this->actingAs($this->employer)
        ->get('/subscription?coupon=NOPE')
        ->assertInertia(fn ($page) => $page->where('couponResult.valid', false));
});

// ───────────────────────── WORKER-DATABASE QUOTA ─────────────────────────

it('gives zero contact quota without an active subscription', function () {
    expect($this->employer->contactDatabaseQuota())->toBe(0);
});

it('grants the plan quota once subscribed', function () {
    activate($this->employer, $this->plan);
    expect($this->employer->fresh()->contactDatabaseQuota())->toBe(2000);
});

it('adds the admin-granted bonus on top of the plan quota', function () {
    activate($this->employer, $this->plan);
    $this->employer->employerProfile()->create(['contact_quota_bonus' => 500]);
    expect($this->employer->fresh()->contactDatabaseQuota())->toBe(2500);
});

it('lets an admin edit a plan\'s contact-database limit', function () {
    $this->actingAs($this->admin)->patch("/admin/plans/{$this->plan->id}", [
        'job_post_limit' => 10, 'contact_unlock_limit' => 50,
        'contact_database_limit' => 3333, 'featured' => true, 'is_active' => true,
    ])->assertRedirect();

    expect($this->plan->fresh()->contactDatabaseLimit())->toBe(3333);
});

it('lets an admin top up an employer\'s bonus quota', function () {
    activate($this->employer, $this->plan);

    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->employer->id}/quota", ['contact_quota_bonus' => 750])
        ->assertRedirect();

    expect($this->employer->fresh()->contactDatabaseQuota())->toBe(2750);
});

it('blocks setting a database quota bonus on a non-employer', function () {
    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->worker->id}/quota", ['contact_quota_bonus' => 100])
        ->assertForbidden();
});

it('exposes the access quota on the worker directory page', function () {
    activate($this->employer, $this->plan);

    $this->actingAs($this->employer)
        ->get('/employer/workers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('access.quota', 2000)->where('access.has_plan', true));
});

// ───────────────────────── EMAIL TEMPLATES ─────────────────────────

it('interpolates placeholders when rendering a template', function () {
    $template = new EmailTemplate([
        'subject' => 'Hi {{ name }}', 'body_html' => '<p>Job: {{ job_title }}</p>',
    ]);
    $rendered = $template->render(['name' => 'Ramesh', 'job_title' => 'Electrician']);

    expect($rendered['subject'])->toBe('Hi Ramesh')
        ->and($rendered['body'])->toBe('<p>Job: Electrician</p>');
});

it('sends an active template but skips an inactive one', function () {
    Mail::fake();

    EmailTemplate::create(['key' => 'welcome', 'name' => 'W', 'subject' => 'Hi {{ name }}', 'body_html' => 'x', 'is_active' => true]);
    TemplatedMailer::send('welcome', 'a@b.com', ['name' => 'Ram']);
    Mail::assertSent(TemplatedMail::class, 1);

    EmailTemplate::create(['key' => 'paused', 'name' => 'P', 'subject' => 's', 'body_html' => 'x', 'is_active' => false]);
    TemplatedMailer::send('paused', 'a@b.com', []);
    Mail::assertSent(TemplatedMail::class, 1); // still just the one
});

it('emails the employer and worker when a worker applies', function () {
    Mail::fake();

    EmailTemplate::create(['key' => 'application_received', 'name' => 'R', 'subject' => 'New', 'body_html' => 'x', 'is_active' => true]);
    EmailTemplate::create(['key' => 'application_submitted', 'name' => 'S', 'subject' => 'Sent', 'body_html' => 'x', 'is_active' => true]);

    $job = $this->employer->jobListings()->create([
        'title' => 'Wiring', 'description' => 'x', 'category' => 'Electrician',
        'status' => \App\Enums\JobStatus::Active->value, 'vacancies' => 1, 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ]);

    $this->actingAs($this->worker)
        ->post("/jobs/{$job->id}/apply", ['cover_note' => 'Available'])
        ->assertRedirect();

    Mail::assertSent(TemplatedMail::class, 2);
});

it('lets an admin edit an email template', function () {
    $template = EmailTemplate::create(['key' => 'application_accepted', 'name' => 'A', 'subject' => 'Old', 'body_html' => 'x', 'is_active' => true]);

    $this->actingAs($this->admin)
        ->patch("/admin/email-templates/{$template->id}", ['subject' => 'New subject', 'body_html' => '<p>Hi</p>', 'is_active' => true])
        ->assertRedirect();

    expect($template->fresh()->subject)->toBe('New subject');
});
