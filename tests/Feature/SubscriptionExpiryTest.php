<?php

use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\JobInvite;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\Gst;
use App\Services\JobPostingGate;
use App\Services\JobRepost;
use App\Services\RazorpayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

it('lets a cancelled plan go at once', function () {
    payBasic();

    signedWebhook('subscription.cancelled', ['id' => 'sub_30', 'paid_count' => 1]);

    expect($this->employer->fresh()->activeSubscription())->toBeNull();
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
