<?php

use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\JobInvite;
use App\Models\Plan;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\AiCredits;
use App\Services\Billing\Gst;
use App\Services\Billing\OrderHistory;
use App\Services\Billing\PlanReminders;
use App\Services\JobPostingGate;
use App\Services\JobRepost;
use App\Services\RazorpayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Razorpay\Api\Api;

/**
 * A plan bought today lasts exactly 30 days: posting, applicants and the
 * Worker Database stop the moment it runs out, unless Razorpay renews it.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    config([
        'scout.driver' => 'null',
        'services.razorpay.key' => 'rzp_test_key',
        'services.razorpay.secret' => 'secret',
        'services.razorpay.webhook_secret' => 'whsec',
        'services.kyc.employer_required' => false,
    ]);

    $this->plan = Plan::create([
        'name' => 'Basic', 'slug' => 'basic', 'type' => Plan::TYPE_JOB, 'price' => 499, 'currency' => 'INR',
        'interval' => Plan::INTERVAL_30_DAYS,
        'features' => ['job_post_limit' => 1, 'contact_unlock_limit' => 25, 'contact_database_limit' => 1000],
        'is_active' => true,
    ]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'email' => 'owner@example.com']);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'state' => 'Haryana', 'free_post_used_at' => now()]);

    // What checkout leaves behind before the payment.
    $this->subscription = $this->employer->subscriptions()->create([
        'plan_id' => $this->plan->id, 'razorpay_subscription_id' => 'sub_30',
        'status' => SubscriptionStatus::Created,
        ...Gst::subscriptionColumns(Gst::quote(499.0, $this->employer->employerProfile)),
    ]);
});

function payBasic(): void
{
    test()->actingAs(test()->employer, 'sanctum')->postJson('/api/v1/employer/plans/callback', [
        'razorpay_payment_id' => 'pay_1',
        'razorpay_subscription_id' => 'sub_30',
        'razorpay_signature' => hash_hmac('sha256', 'pay_1|sub_30', 'secret'),
    ])->assertOk();
}

function signedWebhook(string $event, array $entity): void
{
    $body = json_encode(['event' => $event, 'payload' => [
        'subscription' => ['entity' => $entity],
        'payment' => ['entity' => ['id' => 'pay_'.($entity['paid_count'] ?? 1), 'amount' => 58882]],
    ]]);

    test()->call('POST', '/razorpay/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec'),
    ], $body)->assertOk();
}

it('runs a plan bought today for exactly 30 days', function () {
    $this->travelTo(now()->startOfMinute());
    payBasic();

    $subscription = $this->subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->starts_at->diffInDays($subscription->ends_at))->toEqual(30)
        ->and($this->employer->activeSubscription()?->is($subscription))->toBeTrue();
});

it('stops posting and pauses jobs once the 30 days are over', function () {
    $this->travelTo(now()->startOfMinute());
    payBasic();
    $job = $this->employer->jobListings()->create([
        'title' => 'Weaver', 'description' => 'Handloom', 'status' => JobStatus::Active, 'vacancies' => 1, 'published_at' => now(),
    ]);

    // Day 29: still on the plan, the job takes applications.
    $this->travel(29)->days();
    expect($this->employer->fresh()->activeSubscription())->not->toBeNull()
        ->and($job->fresh()->isOpenForApplications())->toBeTrue();

    // Just past day 30: the plan is over.
    $this->travel(1)->days();
    $this->travel(1)->minutes();
    $employer = $this->employer->fresh();

    expect($employer->activeSubscription())->toBeNull()
        ->and($employer->jobPlanLapsed())->toBeTrue()
        ->and($job->fresh()->isOpenForApplications())->toBeFalse()
        ->and(JobPostingGate::evaluate($employer)['code'])->toBe('subscription_required')
        ->and($employer->contactDatabaseQuota())->toBe(0);
});

it('does not hand a lapsed plan the free post it never spent', function () {
    $this->travelTo(now()->startOfMinute());
    // Bought a plan straight away, so the free post is still unspent.
    $this->employer->employerProfile->update(['free_post_used_at' => null]);
    payBasic();
    $old = $this->employer->jobListings()->create([
        'title' => 'Weaver', 'description' => 'Handloom', 'status' => JobStatus::Closed, 'vacancies' => 1, 'published_at' => now(),
    ]);

    $this->travel(31)->days();

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', [
        'title' => 'Block printer', 'description' => 'Hand block printing on cotton', 'category' => 'Block Printing',
        'vacancies' => 1, 'city' => 'Jaipur', 'state' => 'Rajasthan', 'status' => 'active',
    ])->assertStatus(422)->assertJsonPath('code', 'subscription_required');

    expect(JobRepost::repost($old))->toBe(__('Your plan has ended. Renew it to post jobs.'))
        ->and($this->employer->jobListings()->count())->toBe(1)
        ->and($this->employer->employerProfile->fresh()->free_post_used_at)->toBeNull();
});

it('takes a lapsed plan\'s jobs away from karigars everywhere', function () {
    $this->travelTo(now()->startOfMinute());
    payBasic();
    $job = $this->employer->jobListings()->create([
        'title' => 'Weaver', 'description' => 'Handloom', 'category' => 'Handloom Weaving', 'city' => 'Jaipur',
        'status' => JobStatus::Active, 'vacancies' => 1, 'published_at' => now(),
    ]);
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $worker->workerProfile()->create(['skills' => ['Handloom Weaving'], 'city' => 'Jaipur', 'available' => true]);
    $worker->savedJobs()->create(['job_listing_id' => $job->id]);

    // On the plan, the karigar sees it in the feed and in saved jobs.
    $this->actingAs($worker, 'sanctum');
    $this->getJson('/api/v1/jobs')->assertOk()->assertJsonPath('data.0.id', $job->id);
    $this->getJson('/api/v1/worker/saved')->assertOk()->assertJsonCount(1, 'data');

    $this->travel(31)->days();

    $this->getJson('/api/v1/jobs')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/worker/saved')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/jobs/{$job->id}")->assertNotFound();
    $this->postJson("/api/v1/jobs/{$job->id}/apply")->assertStatus(422)->assertJsonPath('code', 'job_not_hiring');
    $this->get("/jobs/{$job->id}")->assertNotFound();

    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/jobs/{$job->id}/invite", ['worker_id' => $worker->id])
        ->assertStatus(422)->assertJsonPath('code', 'job_not_hiring');
    expect(JobInvite::count())->toBe(0);
});

it('takes the end date Razorpay reports, and moves it 30 days on each renewal', function () {
    $this->travelTo(now()->startOfMinute());
    $start = now()->timestamp;
    $end = now()->addDays(30)->timestamp;

    signedWebhook('subscription.charged', ['id' => 'sub_30', 'paid_count' => 1, 'current_start' => $start, 'current_end' => $end]);
    expect($this->subscription->fresh()->ends_at->timestamp)->toBe($end);

    // Razorpay charges the next 30 days; the plan carries on.
    $this->travel(30)->days();
    signedWebhook('subscription.charged', ['id' => 'sub_30', 'paid_count' => 2, 'current_start' => $end, 'current_end' => $end + 30 * 86400]);

    $subscription = $this->subscription->fresh();
    expect($subscription->ends_at->timestamp)->toBe($end + 30 * 86400)
        ->and($subscription->isActive())->toBeTrue();
});

it('keeps the days paid for when the plan is cancelled mid-cycle, then lets it go', function () {
    Notification::fake();
    payBasic();

    // The employer stopped the UPI mandate ten days in: no more charges, but
    // the 30 days are paid for.
    $this->travel(10)->days();
    signedWebhook('subscription.cancelled', ['id' => 'sub_30', 'paid_count' => 1]);

    $subscription = $this->subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->employer->fresh()->activeSubscription()?->id)->toBe($subscription->id)
        ->and($subscription->willRenew())->toBeFalse()
        ->and(OrderHistory::for($this->employer->fresh())[0]['plan_status'])->toBe('active');
    Notification::assertNothingSent();

    $this->travel(21)->days();

    expect($this->employer->fresh()->activeSubscription())->toBeNull()
        ->and(app(PlanReminders::class)->lapses()->pluck('id')->all())->toBe([$subscription->id]);
});

it('keeps the last cycle running after Razorpay marks the plan completed', function () {
    $this->travelTo(now()->startOfMinute());
    $end = now()->addDays(30)->timestamp;

    // Razorpay can report the plan completed before the last charge itself.
    signedWebhook('subscription.charged', ['id' => 'sub_30', 'paid_count' => 1, 'current_end' => $end]);
    signedWebhook('subscription.completed', ['id' => 'sub_30', 'paid_count' => 12]);
    signedWebhook('subscription.charged', ['id' => 'sub_30', 'paid_count' => 12, 'current_start' => $end, 'current_end' => $end + 30 * 86400]);

    $subscription = $this->subscription->fresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Completed)
        ->and($subscription->ends_at->timestamp)->toBe($end + 30 * 86400)
        ->and($subscription->isActive())->toBeTrue();
});

it('stops an older plan at Razorpay when a new one is bought, even one whose renewal is failing', function () {
    $razorpay = Mockery::mock(RazorpayService::class)->makePartial();
    $razorpay->shouldReceive('cancelSubscription')->once()->with('sub_old', false);
    app()->instance(RazorpayService::class, $razorpay);

    // Last month's plan: its renewal failed and Razorpay is still retrying.
    $old = $this->employer->subscriptions()->create([
        'plan_id' => $this->plan->id, 'razorpay_subscription_id' => 'sub_old',
        'status' => SubscriptionStatus::Pending,
        'starts_at' => now()->subDays(31), 'ends_at' => now()->subDay(),
    ]);

    payBasic();

    expect($old->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->employer->fresh()->activeSubscription()?->id)->toBe($this->subscription->id);
});

it('ends a replaced plan at the switch, and a late report of its payment does not bring it back', function () {
    $razorpay = Mockery::mock(RazorpayService::class)->makePartial();
    $razorpay->shouldReceive('cancelSubscription')->once()->with('sub_30', false);
    app()->instance(RazorpayService::class, $razorpay);

    payBasic();

    $standard = Plan::create([
        'name' => 'Standard', 'slug' => 'standard', 'type' => Plan::TYPE_JOB, 'price' => 999, 'currency' => 'INR',
        'interval' => Plan::INTERVAL_30_DAYS, 'features' => ['job_post_limit' => 3], 'is_active' => true,
    ]);
    $new = $this->employer->subscriptions()->create([
        'plan_id' => $standard->id, 'razorpay_subscription_id' => 'sub_std', 'status' => SubscriptionStatus::Created,
        ...Gst::subscriptionColumns(Gst::quote(999.0, $this->employer->employerProfile)),
    ]);
    $new->activateWithInvoice('pay_std');

    $old = $this->subscription->fresh();
    expect($old->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($old->isActive())->toBeFalse();

    // Razorpay retries the old plan's first-payment webhook hours later.
    signedWebhook('subscription.charged', ['id' => 'sub_30', 'paid_count' => 1, 'current_end' => now()->addDays(30)->timestamp]);

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->subscription->fresh()->isActive())->toBeFalse()
        ->and($this->employer->fresh()->activeSubscription()?->id)->toBe($new->id);
});

it('puts a plan a team member opens on the account, and lets only the owner buy one', function () {
    payBasic();

    $manager = User::factory()->create(['role' => UserRole::Employer->value]);
    TeamMember::create(['employer_id' => $this->employer->id, 'user_id' => $manager->id, 'role' => 'manager']);

    $this->actingAs($manager)->get('/subscription')
        ->assertInertia(fn ($page) => $page->where('current.id', $this->subscription->id));

    $this->actingAs($manager)->post("/subscription/{$this->plan->id}/subscribe")
        ->assertSessionHas('toast.message', 'Only the account owner can change the plan.');

    expect($manager->subscriptions()->count())->toBe(0);
});

it('asks Razorpay for a plan that bills every 30 days', function () {
    $sent = null;
    $plans = Mockery::mock();
    $plans->shouldReceive('create')->once()->andReturnUsing(function (array $payload) use (&$sent) {
        $sent = $payload;

        return ['id' => 'plan_30d'];
    });
    $api = Mockery::mock(Api::class);
    $api->plan = $plans;

    $service = new class($api) extends RazorpayService
    {
        public function __construct(private Api $fake) {}

        protected function api(): Api
        {
            return $this->fake;
        }
    };

    $service->createPlan($this->plan);

    expect($sent['period'])->toBe('daily')->and($sent['interval'])->toBe(30)
        ->and($this->plan->fresh()->razorpay_plan_id)->toBe('plan_30d');
});

it('puts the plan\'s AI call credits in the wallet once per payment', function () {
    $this->travelTo(now()->startOfMinute());
    $this->plan->update(['features' => [...$this->plan->features, 'ai_call_credits' => 5]]);
    $wallet = fn () => AiCredits::for($this->employer->fresh())->balance();
    $start = now()->timestamp;
    $end = now()->addDays(30)->timestamp;

    // The app's callback and Razorpay's webhook both report the first payment.
    payBasic();
    signedWebhook('subscription.charged', ['id' => 'sub_30', 'paid_count' => 1, 'current_start' => $start, 'current_end' => $end]);
    expect($wallet())->toBe(5);

    // Each renewal brings the plan's credits again.
    $this->travel(30)->days();
    signedWebhook('subscription.charged', ['id' => 'sub_30', 'paid_count' => 2, 'current_start' => $end, 'current_end' => $end + 30 * 86400]);
    expect($wallet())->toBe(10)
        ->and($this->plan->fresh()->featureList())->toContain('5 AI calling credits');
});
