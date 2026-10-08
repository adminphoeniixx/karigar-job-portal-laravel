<?php

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Gst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/*
 * Order history: every plan checkout an employer started, paid or not, with
 * the payments made on it and their tax invoices, on the web Plans page and
 * GET /api/v1/employer/orders.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null', 'billing.invoice_prefix' => 'KRG']);
    Setting::set(Gst::SELLER_GSTIN_KEY, '06AAFCP6967R1ZF');
    Mail::fake();

    $this->plan = Plan::create([
        'name' => 'Basic', 'slug' => 'basic', 'price' => 499, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 2], 'is_active' => true,
    ]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'email' => 'owner@example.com']);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'state' => 'Haryana']);
});

function orderFor(User $employer, Plan $plan, string $razorpayId): Subscription
{
    return $employer->subscriptions()->create([
        'plan_id' => $plan->id, 'razorpay_subscription_id' => $razorpayId,
        'status' => SubscriptionStatus::Created,
        ...Gst::subscriptionColumns(Gst::quote((float) $plan->price, $employer->employerProfile)),
    ]);
}

it('lists paid, unfinished and failed-renewal orders with their invoices', function () {
    $this->travelTo(Carbon::parse('2026-10-07 06:00', 'UTC'));

    // Paid once, then Razorpay gave up on the renewal. (Bought first: a
    // newer plan of the same type cancels the older one.)
    $halted = orderFor($this->employer, $this->plan, 'sub_halted');
    $halted->activateWithInvoice('pay_3');

    // Paid, then renewed once: two payments, two invoices.
    $paid = orderFor($this->employer, $this->plan, 'sub_paid');
    $paid->activateWithInvoice('pay_1');
    $paid->renew(2, 'pay_2', 588.82, now(), now()->addMonth());
    $halted->update(['status' => SubscriptionStatus::Halted]);

    // Opened an hour ago and never paid: not completed.
    $this->travelTo(Carbon::parse('2026-10-07 05:00', 'UTC'));
    $abandoned = orderFor($this->employer, $this->plan, 'sub_abandoned');

    // Opened just now: still awaiting payment.
    $this->travelTo(Carbon::parse('2026-10-07 06:00', 'UTC'));
    $fresh = orderFor($this->employer, $this->plan, 'sub_fresh');

    $orders = collect($this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/orders')
        ->assertOk()
        ->json('orders'))->keyBy('id');

    expect($orders)->toHaveCount(4)
        ->and($orders->keys()->all())->toBe([$fresh->id, $abandoned->id, $paid->id, $halted->id]);

    expect($orders[$paid->id])
        ->payment_status->toBe('paid')
        ->plan_status->toBe('active')
        ->payments->toHaveCount(2)
        ->total_paid->toBe(round(588.82 * 2, 2));
    expect($orders[$paid->id]['payments'][0])
        ->renewal->toBeFalse()
        ->invoice_number->toBe('KRG/26-27/00002')
        ->pdf_url->toEndWith('/pdf');
    expect($orders[$paid->id]['payments'][1]['renewal'])->toBeTrue();

    expect($orders[$abandoned->id])
        ->payment_status->toBe('not_completed')
        ->payment_label->toBe('Payment not completed')
        ->plan_status->toBe('none')
        ->payments->toBe([]);
    expect($orders[$fresh->id]['payment_status'])->toBe('pending');
    expect($orders[$halted->id])->payment_status->toBe('renewal_failed')->payments->toHaveCount(1);
});

it('shows only the employer their own orders, on the web too', function () {
    $mine = orderFor($this->employer, $this->plan, 'sub_mine');
    $mine->activateWithInvoice('pay_mine');

    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $other->employerProfile()->create(['company_name' => 'Other Co', 'state' => 'Delhi']);
    $this->travel(-2)->hours();
    orderFor($other, $this->plan, 'sub_other');
    $this->travelBack();

    $this->actingAs($this->employer)->get('/subscription')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/Pricing')
            ->has('orders', 1)
            ->where('orders.0.id', $mine->id)
            ->where('orders.0.payment_status', 'paid')
            ->where('orders.0.payments.0.web_pdf_url', route('invoices.pdf', $mine->invoices()->first())));

    $this->actingAs($other, 'sanctum')->getJson('/api/v1/employer/orders')
        ->assertOk()
        ->assertJsonCount(1, 'orders')
        ->assertJsonPath('orders.0.payment_status', 'not_completed');
});

it('disables a plan the employer already holds and refuses to sell it twice', function () {
    config(['services.razorpay.key' => 'rzp_test_key', 'services.razorpay.secret' => 'secret']);
    $other = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => 1999, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 25], 'is_active' => true,
    ]);

    $order = orderFor($this->employer, $this->plan, 'sub_owned');
    $order->activateWithInvoice('pay_owned');

    $plans = collect($this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/plans')->assertOk()->json('plans'))->keyBy('id');

    expect($plans[$this->plan->id])
        ->already_purchased->toBeTrue()
        ->can_purchase->toBeFalse()
        ->purchase_note->toStartWith('You already have this plan, active till');
    expect($plans[$other->id])->already_purchased->toBeFalse()->can_purchase->toBeTrue();

    $this->postJson("/api/v1/employer/plans/{$this->plan->id}/subscribe")
        ->assertStatus(422)->assertJsonPath('code', 'already_subscribed');

    $this->actingAs($this->employer)->post("/subscription/{$this->plan->id}/subscribe")
        ->assertRedirect()->assertSessionHas('toast.type', 'error');
    expect($this->employer->subscriptions()->count())->toBe(1);
});
