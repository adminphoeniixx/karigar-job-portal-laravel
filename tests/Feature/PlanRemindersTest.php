<?php

use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Mail\TemplatedMail;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\PlanReminderNotification;
use App\Services\Billing\Gst;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Employers hear about their plan before it renews or ends, when a renewal
 * payment fails, and once it has ended; each notice once a billing cycle.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'scout.driver' => 'null',
        'services.razorpay.key' => 'rzp_test_key',
        'services.razorpay.secret' => 'secret',
        'services.razorpay.webhook_secret' => 'whsec',
    ]);

    $this->plan = Plan::create([
        'name' => 'Basic', 'slug' => 'basic', 'type' => Plan::TYPE_JOB, 'price' => 499, 'currency' => 'INR',
        'interval' => Plan::INTERVAL_30_DAYS, 'features' => ['job_post_limit' => 1], 'is_active' => true,
    ]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'email' => 'owner@example.com']);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'state' => 'Haryana']);

    $this->subscription = $this->employer->subscriptions()->create([
        'plan_id' => $this->plan->id, 'razorpay_subscription_id' => 'sub_r',
        'status' => SubscriptionStatus::Active, 'starts_at' => now()->subDays(28), 'ends_at' => now()->addDays(2),
        ...Gst::subscriptionColumns(Gst::quote(499.0, $this->employer->employerProfile)),
    ]);
});

function planWebhook(string $event, array $entity = []): void
{
    $body = json_encode(['event' => $event, 'payload' => ['subscription' => ['entity' => ['id' => 'sub_r', ...$entity]]]]);

    test()->call('POST', '/razorpay/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec'),
    ], $body)->assertOk();
}

it('tells the employer a plan renews in a few days and what it charges, once a cycle', function () {
    Notification::fake();

    $this->artisan('plans:remind')->assertSuccessful();
    $this->artisan('plans:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->employer, PlanReminderNotification::class, 1);
    Notification::assertSentTo($this->employer, PlanReminderNotification::class, fn ($n) => $n->state === PlanReminderNotification::RENEWING);
    expect($this->subscription->fresh()->reminded_for->equalTo($this->subscription->ends_at))->toBeTrue();

    // The renewal moves the end on: the next cycle gets its own reminder.
    $this->subscription->update(['ends_at' => now()->addDays(32)]);
    $this->travel(30)->days();
    $this->artisan('plans:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->employer, PlanReminderNotification::class, 2);
});

it('says a plan ends, not renews, when Razorpay will not charge again', function () {
    Notification::fake();
    Invoice::issue($this->subscription, cycle: 12, amounts: Gst::invoiceColumns($this->subscription));

    $this->artisan('plans:remind')->assertSuccessful();

    Notification::assertSentTo($this->employer, PlanReminderNotification::class, fn ($n) => $n->state === PlanReminderNotification::ENDING);
});

it('says nothing a week out', function () {
    Notification::fake();
    $this->subscription->update(['ends_at' => now()->addDays(7)]);

    $this->artisan('plans:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

it('announces a plan that ran out once, waiting a few hours for a renewal charge', function () {
    Notification::fake();
    $this->subscription->update(['ends_at' => now()->subHours(2), 'reminded_for' => now()->subHours(2)]);

    // It renews by itself: the charge may still be on its way.
    $this->artisan('plans:remind')->assertSuccessful();
    Notification::assertNothingSent();

    $this->travel(5)->hours();
    $this->artisan('plans:remind')->assertSuccessful();
    $this->artisan('plans:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->employer, PlanReminderNotification::class, 1);
    Notification::assertSentTo($this->employer, PlanReminderNotification::class, fn ($n) => $n->state === PlanReminderNotification::ENDED);
});

it('announces a plan that will not renew as soon as it ends', function () {
    Notification::fake();
    $this->subscription->update(['razorpay_subscription_id' => null, 'ends_at' => now()->subMinutes(10), 'reminded_for' => now()->subMinutes(10)]);

    $this->artisan('plans:remind')->assertSuccessful();

    Notification::assertSentTo($this->employer, PlanReminderNotification::class, fn ($n) => $n->state === PlanReminderNotification::ENDED);
});

it('does not announce a plan another one replaced, nor one that ended long ago', function () {
    Notification::fake();
    $this->subscription->update(['status' => SubscriptionStatus::Cancelled]);
    $this->employer->subscriptions()->create([
        'plan_id' => $this->plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now(), 'ends_at' => now()->addDays(30), 'total_amount' => 588.82,
    ]);

    $old = $this->employer->subscriptions()->create([
        'plan_id' => $this->plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDays(60), 'ends_at' => now()->subDays(30), 'total_amount' => 588.82,
    ]);

    $this->artisan('plans:remind')->assertSuccessful();

    Notification::assertNotSentTo($this->employer, PlanReminderNotification::class, fn ($n) => $n->state === PlanReminderNotification::ENDED);
    expect($old->fresh()->lapse_notified_for)->toBeNull();
});

it('tells the employer a renewal payment failed, once, and that the plan ended when Razorpay gives up', function () {
    Notification::fake();
    $this->subscription->update(['ends_at' => now()->subMinutes(5)]);

    planWebhook('subscription.pending');
    planWebhook('subscription.pending');

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Pending);
    Notification::assertSentToTimes($this->employer, PlanReminderNotification::class, 1);
    Notification::assertSentTo($this->employer, PlanReminderNotification::class, fn ($n) => $n->state === PlanReminderNotification::PAYMENT_FAILED);

    planWebhook('subscription.halted');
    planWebhook('subscription.halted');
    $this->artisan('plans:remind')->assertSuccessful();

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Halted);
    Notification::assertSentToTimes($this->employer, PlanReminderNotification::class, 2);
    Notification::assertSentTo($this->employer, PlanReminderNotification::class, fn ($n) => $n->state === PlanReminderNotification::ENDED);
});

it('emails what stopped with the plan', function () {
    Mail::fake();
    $this->seed(EmailTemplateSeeder::class);
    foreach (['Mason', 'Carpenter'] as $title) {
        $this->employer->jobListings()->create(['title' => $title, 'description' => 'Work', 'status' => JobStatus::Active->value, 'vacancies' => 1]);
    }
    $this->subscription->update(['razorpay_subscription_id' => null, 'ends_at' => now()->subMinutes(10), 'reminded_for' => now()->subMinutes(10)]);

    $this->artisan('plans:remind')->assertSuccessful();

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('owner@example.com')
        && str_contains($mail->subjectLine, 'Your Basic plan has ended')
        && str_contains($mail->bodyHtml, 'Your 2 live jobs are hidden from karigars.'));
});
