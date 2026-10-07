<?php

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Mail\TemplatedMail;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Gst;
use App\Services\RazorpayService;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
 * One tax invoice per payment: the first payment and every Razorpay renewal,
 * numbered in one gapless series per financial year, emailed to the address
 * the employer gave at checkout.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'scout.driver' => 'null',
        'services.razorpay.key' => 'rzp_test_key',
        'services.razorpay.secret' => 'secret',
        'services.razorpay.webhook_secret' => 'whsec',
        'billing.invoice_prefix' => 'KRG',
    ]);
    Setting::set(Gst::SELLER_GSTIN_KEY, '06AAFCP6967R1ZF');
    Mail::fake();
    $this->seed(EmailTemplateSeeder::class);

    $this->plan = Plan::create([
        'name' => 'Basic', 'slug' => 'basic', 'price' => 499, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 2], 'is_active' => true,
    ]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'email' => 'owner@example.com']);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'state' => 'Haryana']);
});

function openSubscription(User $employer, Plan $plan, string $razorpayId = 'sub_1', float $discount = 0): Subscription
{
    return $employer->subscriptions()->create([
        'plan_id' => $plan->id, 'razorpay_subscription_id' => $razorpayId,
        'discount_amount' => $discount ?: null,
        'status' => SubscriptionStatus::Created,
        ...Gst::subscriptionColumns(Gst::quote((float) $plan->price - $discount, $employer->employerProfile)),
    ]);
}

/**
 * POST a signed Razorpay webhook, the way Razorpay sends one.
 *
 * @param  array<string, mixed>  $subscription
 * @param  array<string, mixed>|null  $payment
 */
function razorpayWebhook(string $event, array $subscription, ?array $payment = null): TestResponse
{
    $payload = ['event' => $event, 'payload' => ['subscription' => ['entity' => $subscription]]];

    if ($payment !== null) {
        $payload['payload']['payment'] = ['entity' => $payment];
    }

    $body = json_encode($payload);

    return test()->call('POST', '/razorpay/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec'),
    ], $body);
}

it('invoices every renewal with the next number in the series, once', function () {
    $this->travelTo(Carbon::parse('2026-10-07 06:00', 'UTC'));
    $subscription = openSubscription($this->employer, $this->plan);
    $subscription->activateWithInvoice('pay_1');

    $this->travelTo(Carbon::parse('2026-11-07 06:00', 'UTC'));
    $renewal = [
        'id' => 'sub_1', 'paid_count' => 2,
        'current_start' => now()->timestamp, 'current_end' => now()->addMonth()->timestamp,
    ];

    razorpayWebhook('subscription.charged', $renewal, ['id' => 'pay_2', 'amount' => 58882])->assertOk();
    razorpayWebhook('subscription.charged', $renewal, ['id' => 'pay_2', 'amount' => 58882])->assertOk(); // delivered twice

    $invoices = $subscription->invoices()->orderBy('cycle')->get();

    expect($invoices)->toHaveCount(2)
        ->and($invoices->pluck('number')->all())->toBe(['KRG/26-27/00001', 'KRG/26-27/00002'])
        ->and($invoices[1]->razorpay_payment_id)->toBe('pay_2')
        ->and((float) $invoices[1]->total_amount)->toBe(588.82)
        ->and((float) $invoices[1]->cgst_amount)->toBe(44.91)
        ->and($invoices[1]->period_start->toDateString())->toBe('2026-11-07')
        ->and($subscription->fresh()->ends_at->toDateString())->toBe('2026-12-07');

    // One email per invoice, the renewal's carrying its own PDF.
    Mail::assertQueued(TemplatedMail::class, 2);
    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail) => isset($mail->files['Invoice-KRG-26-27-00002.pdf']));
});

it('issues one first invoice whichever of the callback and the webhooks arrives first', function () {
    $subscription = openSubscription($this->employer, $this->plan);

    // activated can come without a payment; charged brings it.
    razorpayWebhook('subscription.activated', ['id' => 'sub_1', 'paid_count' => 1])->assertOk();
    razorpayWebhook('subscription.charged', ['id' => 'sub_1', 'paid_count' => 1], ['id' => 'pay_1', 'amount' => 58882])->assertOk();
    $subscription->fresh()->activateWithInvoice('pay_1'); // the browser callback, last

    expect($subscription->invoices()->count())->toBe(1)
        ->and($subscription->invoices()->first()->razorpay_payment_id)->toBe('pay_1')
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);

    Mail::assertQueued(TemplatedMail::class, 1);
});

it('works the GST back out when a renewal charges a different amount', function () {
    // A coupon that only covered the first month: ₹100 off, renewals at full price.
    $subscription = openSubscription($this->employer, $this->plan, discount: 100);
    $subscription->activateWithInvoice('pay_1');

    razorpayWebhook('subscription.charged', ['id' => 'sub_1', 'paid_count' => 2], ['id' => 'pay_2', 'amount' => 58882])->assertOk();

    $first = $subscription->invoices()->where('cycle', 1)->first();
    $renewal = $subscription->invoices()->where('cycle', 2)->first();

    expect((float) $first->total_amount)->toBe(470.82)
        ->and((float) $first->discount_amount)->toBe(100.0)
        ->and((float) $renewal->subtotal_amount)->toBe(499.0)
        ->and((float) $renewal->gst_amount)->toBe(89.82)
        ->and((float) $renewal->cgst_amount + (float) $renewal->sgst_amount)->toBe(89.82)
        ->and($renewal->discount_amount)->toBeNull();
});

it('numbers invoices without gaps and starts again each financial year', function () {
    $other = User::factory()->create(['role' => UserRole::Employer->value]);

    // 11:59 PM on 31 March in India.
    $this->travelTo(Carbon::parse('2027-03-31 18:29', 'UTC'));
    openSubscription($this->employer, $this->plan, 'sub_a')->activateWithInvoice();
    openSubscription($other, $this->plan, 'sub_b')->activateWithInvoice();
    // A checkout that was never paid takes no number.
    openSubscription($other, $this->plan, 'sub_unpaid');

    // 12:01 AM on 1 April: a new financial year.
    $this->travelTo(Carbon::parse('2027-03-31 18:31', 'UTC'));
    openSubscription($other, $this->plan, 'sub_c')->activateWithInvoice();

    expect(Invoice::orderBy('id')->pluck('number')->all())
        ->toBe(['KRG/26-27/00001', 'KRG/26-27/00002', 'KRG/27-28/00001']);
});

it('asks a phone-OTP employer for an invoice email before the payment, and sends the invoice there', function () {
    $razorpay = Mockery::mock(RazorpayService::class)->makePartial();
    $razorpay->shouldReceive('ensurePlan')->andReturnNull();
    $razorpay->shouldReceive('createSubscription')->andReturn(['id' => 'sub_otp']);
    app()->instance(RazorpayService::class, $razorpay);

    $otp = User::factory()->create(['role' => UserRole::Employer->value, 'email' => '9000000009@phone.karigar']);

    $this->actingAs($otp)->get('/subscription')
        ->assertInertia(fn ($page) => $page->where('billingEmail', null));

    $this->actingAs($otp)->post("/subscription/{$this->plan->id}/subscribe")
        ->assertSessionHasErrors('email');
    $this->actingAs($otp)->post("/subscription/{$this->plan->id}/subscribe", ['email' => 'owner@example.com'])
        ->assertSessionHasErrors('email'); // someone else's

    $this->actingAs($otp)->post("/subscription/{$this->plan->id}/subscribe", ['email' => 'accounts@otp-firm.in'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('subscription/Checkout'));

    expect($otp->fresh()->email)->toBe('accounts@otp-firm.in');

    Subscription::where('razorpay_subscription_id', 'sub_otp')->first()->activateWithInvoice('pay_otp');

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('accounts@otp-firm.in'));
});

it('takes the invoice email in the app checkout too, and lists it on the plans screen', function () {
    $razorpay = Mockery::mock(RazorpayService::class)->makePartial();
    $razorpay->shouldReceive('ensurePlan')->andReturnNull();
    $razorpay->shouldReceive('createSubscription')->andReturn(['id' => 'sub_app_1'], ['id' => 'sub_app_2']);
    app()->instance(RazorpayService::class, $razorpay);

    $otp = User::factory()->create(['role' => UserRole::Employer->value, 'email' => '9000000008@phone.karigar']);

    $this->actingAs($otp, 'sanctum')->getJson('/api/v1/employer/plans')
        ->assertJsonPath('billing_email', null);

    // Older app builds send no email; the checkout still opens.
    $this->actingAs($otp, 'sanctum')->postJson("/api/v1/employer/plans/{$this->plan->id}/subscribe")->assertCreated();

    $this->actingAs($otp, 'sanctum')
        ->postJson("/api/v1/employer/plans/{$this->plan->id}/subscribe", ['email' => 'app@otp-firm.in'])
        ->assertCreated();

    $this->actingAs($otp, 'sanctum')->getJson('/api/v1/employer/plans')
        ->assertJsonPath('billing_email', 'app@otp-firm.in');
});

it('counts renewals in the admin revenue report', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $subscription = openSubscription($this->employer, $this->plan);
    $subscription->activateWithInvoice('pay_1');
    $subscription->renew(cycle: 2, paymentId: 'pay_2');

    $this->actingAs($admin)->get('/admin/reports')
        ->assertInertia(fn ($page) => $page->where('tiles.revenue', fn ($v) => (float) $v === 1177.64));
});

it('copies invoices stored on subscriptions into the table, once', function () {
    $legacy = openSubscription($this->employer, $this->plan, 'sub_legacy');
    $legacy->update([
        'status' => SubscriptionStatus::Active, 'starts_at' => now(),
        'invoice_number' => 'KRG-2026-00008', 'invoiced_at' => now(),
    ]);

    $this->artisan('billing:backfill-invoices')->assertSuccessful();
    $this->artisan('billing:backfill-invoices')->assertSuccessful();

    expect(Invoice::count())->toBe(1)
        ->and(Invoice::first()->number)->toBe('KRG-2026-00008')
        ->and(Invoice::first()->sequence)->toBeNull();

    // Its renewal goes into the new series.
    $legacy->fresh()->renew(cycle: 2);
    expect($legacy->invoices()->where('cycle', 2)->value('number'))->toStartWith('KRG/');
});

it('invoices the renewals Razorpay charged before renewals were invoiced', function () {
    $this->freezeSecond();
    $subscription = openSubscription($this->employer, $this->plan, 'sub_old');
    $subscription->update(['status' => SubscriptionStatus::Active, 'starts_at' => now()->subMonths(3)]);
    $subscription->invoices()->create([
        ...Gst::invoiceColumns($subscription),
        'employer_id' => $this->employer->id, 'cycle' => 1, 'number' => 'KRG-2026-00008',
        'issued_at' => now()->subMonths(3), 'plan_name' => 'Basic',
    ]);

    $month = fn (int $ago) => now()->subMonths($ago)->timestamp;
    $razorpay = Mockery::mock(RazorpayService::class)->makePartial();
    $razorpay->shouldReceive('subscriptionCharges')->with('sub_old')->andReturn([
        ['payment_id' => 'pay_1', 'amount' => 588.82, 'paid_at' => $month(3), 'start' => $month(3), 'end' => $month(2)],
        ['payment_id' => 'pay_2', 'amount' => 588.82, 'paid_at' => $month(2), 'start' => $month(2), 'end' => $month(1)],
        ['payment_id' => 'pay_3', 'amount' => 588.82, 'paid_at' => $month(1), 'start' => $month(1), 'end' => $month(0)],
    ]);
    app()->instance(RazorpayService::class, $razorpay);

    $this->artisan('billing:invoice-missed-renewals --dry-run')->expectsOutputToContain('2 renewal(s) would be invoiced')->assertSuccessful();
    expect($subscription->invoices()->count())->toBe(1);

    $this->artisan('billing:invoice-missed-renewals')->assertSuccessful();
    $this->artisan('billing:invoice-missed-renewals')->expectsOutputToContain('No missed renewals')->assertSuccessful();

    $renewals = $subscription->invoices()->where('cycle', '>', 1)->orderBy('cycle')->get();

    expect($renewals->pluck('razorpay_payment_id')->all())->toBe(['pay_2', 'pay_3'])
        ->and($renewals->pluck('number')->every(fn ($n) => str_starts_with($n, 'KRG/')))->toBeTrue()
        ->and((float) $renewals[0]->total_amount)->toBe(588.82)
        ->and($renewals[0]->period_start->timestamp)->toBe($month(2))
        // The subscription itself is left as it is.
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);

    Mail::assertQueued(TemplatedMail::class, 2);
});
