<?php

use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Mail\TemplatedMail;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Gst;
use App\Services\Billing\SubscriptionCheckout;
use App\Services\RazorpayService;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/*
 * GST on plan sales (rate and seller from admin settings, CGST+SGST inside the
 * seller's state, IGST outside it, charged in the Razorpay amount), the invoice
 * email, and job drafts that do not spend the plan's job posts.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->plan = Plan::create([
        'name' => 'Basic', 'slug' => 'basic', 'price' => 499, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 2, 'contact_unlock_limit' => 20, 'contact_database_limit' => 1000, 'featured' => false],
        'is_active' => true,
    ]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'email' => 'owner@example.com']);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'state' => 'Haryana']);

    Setting::set(Gst::SELLER_GSTIN_KEY, '06AAFCP6967R1ZF');
});

function subscribeActive(User $employer, Plan $plan): Subscription
{
    return Subscription::create([
        'employer_id' => $employer->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);
}

function jobPayload(array $overrides = []): array
{
    return [
        'title' => 'Mason for villa', 'description' => 'Brick work', 'vacancies' => 2,
        'contact_mode' => 'apply', 'requires_worker_fee' => false, 'status' => 'active', ...$overrides,
    ];
}

// ───────────────────────── GST ─────────────────────────

it('splits GST into CGST and SGST for a buyer in the seller state', function () {
    $quote = Gst::quote(499, $this->employer->employerProfile);

    expect($quote['gst'])->toBe(89.82)
        ->and($quote['cgst'])->toBe(44.91)
        ->and($quote['sgst'])->toBe(44.91)
        ->and($quote['igst'])->toBe(0.0)
        ->and($quote['total'])->toBe(588.82)
        ->and($quote['place_of_supply'])->toBe('Haryana (06)');
});

it('charges IGST to a buyer in another state, reading the state from their GSTIN first', function () {
    $this->employer->employerProfile->update(['state' => 'Haryana', 'gstin' => '27AAACR5055K1Z7']);

    $quote = Gst::quote(499, $this->employer->employerProfile->fresh());

    expect($quote['igst'])->toBe(89.82)
        ->and($quote['cgst'])->toBe(0.0)
        ->and($quote['place_of_supply'])->toBe('Maharashtra (27)');
});

it('follows the admin rate, and charges nothing when GST is switched off', function () {
    Setting::set(Gst::PERCENT_KEY, '12');
    expect(Gst::quote(1000)['gst'])->toBe(120.0);

    Setting::set(Gst::ENABLED_KEY, '0');
    expect(Gst::quote(1000)['gst'])->toBe(0.0)
        ->and(Gst::quote(1000)['total'])->toBe(1000.0);
});

it('lets an admin save billing settings and rejects a bad GSTIN', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $valid = [
        'gst_enabled' => true, 'gst_percent' => 18, 'seller_name' => 'Phoeniixx Designs Private Limited',
        'seller_address' => 'Phase IV, Gurugram, Haryana 122015', 'seller_gstin' => '06aafcp6967r1zf', 'sac_code' => '998365',
    ];

    $this->actingAs($admin)->patch('/admin/settings/billing', [...$valid, 'seller_gstin' => 'NOTAGSTIN'])
        ->assertSessionHasErrors('seller_gstin');

    $this->actingAs($admin)->patch('/admin/settings/billing', $valid)->assertSessionHasNoErrors();

    expect(Gst::seller()['gstin'])->toBe('06AAFCP6967R1ZF')
        ->and(Gst::seller()['state_code'])->toBe('06');
});

it('creates the Razorpay plan at the price with GST, and a new one when the price changes', function () {
    $razorpay = Mockery::mock(RazorpayService::class)->makePartial();
    $created = [];
    $razorpay->shouldReceive('createPlan')->andReturnUsing(function (Plan $plan) use (&$created) {
        $created[] = $plan->grossPrice();
        $plan->update(['razorpay_plan_id' => 'plan_'.count($created), 'razorpay_amount' => $plan->grossPrice()]);

        return 'plan_'.count($created);
    });
    $razorpay->shouldReceive('createSubscription')->andReturn(['id' => 'sub_1'], ['id' => 'sub_2'], ['id' => 'sub_3']);

    $checkout = new SubscriptionCheckout($razorpay);

    $subscription = $checkout->start($this->employer, $this->plan);
    $checkout->start($this->employer, $this->plan->fresh());

    expect($created)->toBe([588.82])
        ->and((float) $subscription->total_amount)->toBe(588.82)
        ->and((float) $subscription->cgst_amount)->toBe(44.91)
        ->and($subscription->seller_gstin)->toBe('06AAFCP6967R1ZF');

    $this->plan->update(['price' => 599]);
    $checkout->start($this->employer, $this->plan->fresh());

    expect($created)->toBe([588.82, 706.82]);
});

it('lists what a plan gives in words, for the apps', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/plans')
        ->assertOk()
        ->assertJsonPath('plans.0.feature_list.0', '2 job posts per month')
        ->assertJsonPath('plans.0.feature_list.1', '20 contact unlocks')
        ->assertJsonPath('plans.0.price_with_gst', 588.82);
});

// ───────────────────────── INVOICE EMAIL ─────────────────────────

it('emails the invoice with the PDF once the payment lands, and only once', function () {
    Mail::fake();
    $this->seed(EmailTemplateSeeder::class);

    $subscription = $this->employer->subscriptions()->create([
        'plan_id' => $this->plan->id, 'razorpay_subscription_id' => 'sub_x',
        'status' => SubscriptionStatus::Created, ...Gst::subscriptionColumns(Gst::quote(499, $this->employer->employerProfile)),
    ]);

    $subscription->activateWithInvoice();
    $subscription->fresh()->activateWithInvoice(); // the webhook arriving after the callback

    Mail::assertQueued(TemplatedMail::class, 1);
    Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail) use ($subscription) {
        $pdf = base64_decode($mail->files["Invoice-{$subscription->fresh()->invoice_number}.pdf"] ?? '');

        return $mail->hasTo('owner@example.com')
            && str_starts_with($pdf, '%PDF')
            && str_contains($mail->bodyHtml, 'CGST 9% ₹44.91 + SGST 9% ₹44.91')
            && str_contains($mail->bodyHtml, '₹588.82');
    });
});

// ───────────────────────── DRAFTS AND THE POSTING LIMIT ─────────────────────────

it('saves a draft with only a title, without spending a job post', function () {
    subscribeActive($this->employer, $this->plan);

    $this->actingAs($this->employer)
        ->post('/employer/jobs', ['title' => 'Half-written job', 'status' => 'draft', 'contact_mode' => 'apply'])
        ->assertRedirect('/employer/jobs');

    $draft = $this->employer->jobListings()->first();

    expect($draft->status)->toBe(JobStatus::Draft)
        ->and($draft->published_at)->toBeNull();
});

it('counts only jobs published this billing cycle against the plan', function () {
    subscribeActive($this->employer, $this->plan);

    // Posted last month, before this cycle: does not count.
    $old = $this->employer->jobListings()->create([...jobPayload(), 'status' => 'closed']);
    $old->forceFill(['published_at' => now()->subMonths(2)])->saveQuietly();

    $this->actingAs($this->employer)->post('/employer/jobs', jobPayload())->assertSessionHasNoErrors();
    $this->actingAs($this->employer)->post('/employer/jobs', jobPayload())->assertSessionHasNoErrors();
    expect($this->employer->jobListings()->whereNotNull('published_at')->count())->toBe(3);

    // The limit is 2: a third post this cycle is refused, a draft is not.
    $this->actingAs($this->employer)->post('/employer/jobs', jobPayload())
        ->assertSessionHas('toast', fn ($t) => $t['type'] === 'error');
    $this->actingAs($this->employer)->post('/employer/jobs', jobPayload(['status' => 'draft']))
        ->assertRedirect('/employer/jobs');

    expect($this->employer->jobListings()->count())->toBe(4)
        ->and($this->employer->jobListings()->where('status', 'draft')->count())->toBe(1);
});

it('checks the plan when a draft is published, and stamps it', function () {
    subscribeActive($this->employer, $this->plan);
    $draft = $this->employer->jobListings()->create(jobPayload(['status' => 'draft']));

    $this->actingAs($this->employer)
        ->patch("/employer/jobs/{$draft->id}", jobPayload())
        ->assertRedirect('/employer/jobs');

    expect($draft->fresh()->status)->toBe(JobStatus::Active)
        ->and($draft->fresh()->published_at)->not->toBeNull();

    // Plan now has 1 of 2 used; fill it, then a second draft cannot go live.
    $this->employer->jobListings()->create(jobPayload());
    $second = $this->employer->jobListings()->create(jobPayload(['status' => 'draft']));

    $this->actingAs($this->employer)->patch("/employer/jobs/{$second->id}", jobPayload())
        ->assertSessionHas('toast', fn ($t) => $t['type'] === 'error');

    expect($second->fresh()->status)->toBe(JobStatus::Draft);
});

it('does not spend the free first post on a draft', function () {
    Setting::set('first_post_free_enabled', '1');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', ['title' => 'Draft', 'status' => 'draft', 'contact_mode' => 'apply'])
        ->assertCreated()
        ->assertJsonPath('job.is_draft', true);

    expect($this->employer->employerProfile->fresh()->free_post_used_at)->toBeNull();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', jobPayload())
        ->assertCreated();

    expect($this->employer->employerProfile->fresh()->free_post_used_at)->not->toBeNull();
});
