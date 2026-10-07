<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RazorpayService;
use Illuminate\Validation\Rule;

/**
 * Opens a subscription at Razorpay and records it with its GST breakup. The
 * web checkout and the app's billing API both start here, so they cannot
 * disagree on what the employer is charged.
 */
class SubscriptionCheckout
{
    public function __construct(private RazorpayService $razorpay) {}

    public function start(User $account, Plan $plan, ?Coupon $coupon = null, float $discount = 0.0): Subscription
    {
        // The Razorpay plan is what actually gets charged, so it must carry
        // today's price and GST before a subscription is opened on it.
        $this->razorpay->ensurePlan($plan);

        $remote = $this->razorpay->createSubscription($plan->refresh(), offerId: $coupon?->razorpay_offer_id);

        $quote = Gst::quote((float) $plan->price - $discount, $account->employerProfile);

        return $account->subscriptions()->create([
            'plan_id' => $plan->id,
            'coupon_id' => $coupon?->id,
            'discount_amount' => $coupon ? $discount : null,
            ...Gst::subscriptionColumns($quote),
            'razorpay_subscription_id' => $remote['id'],
            'status' => SubscriptionStatus::Created,
        ]);
    }

    /**
     * Rules for the email an employer gives at checkout for their invoices.
     * It becomes the account's email, the same one the profile page edits.
     *
     * @return array<int, mixed>
     */
    public static function emailRules(User $account): array
    {
        return ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($account->id)];
    }

    /**
     * Save the invoice email given at checkout. A phone-OTP account starts
     * with a placeholder address that reaches nobody, and the tax invoice
     * would never be sent.
     */
    public static function saveBillingEmail(User $account, ?string $email): void
    {
        $email = trim((string) $email);

        if ($email !== '' && strcasecmp($email, (string) $account->email) !== 0) {
            $account->update(['email' => $email]);
        }
    }

    /**
     * The amounts a checkout screen shows for a subscription it just opened.
     *
     * @return array<string, mixed>
     */
    public static function amounts(Subscription $subscription): array
    {
        return [
            'discount' => (float) ($subscription->discount_amount ?? 0),
            'subtotal' => (float) $subscription->subtotal_amount,
            'gst_percent' => (float) $subscription->gst_percent,
            'gst' => (float) $subscription->gst_amount,
            'cgst' => (float) $subscription->cgst_amount,
            'sgst' => (float) $subscription->sgst_amount,
            'igst' => (float) $subscription->igst_amount,
            'place_of_supply' => $subscription->place_of_supply,
            'total' => (float) $subscription->total_amount,
        ];
    }
}
