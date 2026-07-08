<?php

namespace App\Services;

use App\Models\Plan;
use Razorpay\Api\Api;
use RuntimeException;
use Throwable;

class RazorpayService
{
    public function configured(): bool
    {
        return ! empty(config('services.razorpay.key'))
            && ! empty(config('services.razorpay.secret'));
    }

    protected function api(): Api
    {
        if (! $this->configured()) {
            throw new RuntimeException('Razorpay keys are not configured.');
        }

        return new Api(
            config('services.razorpay.key'),
            config('services.razorpay.secret'),
        );
    }

    /**
     * Create a Razorpay plan for the given local plan and return its id.
     *
     * Razorpay `period` accepts daily|weekly|monthly|yearly; our local
     * `interval` (monthly|yearly) maps straight across with interval count 1.
     */
    public function createPlan(Plan $plan): string
    {
        $razorpayPlan = $this->api()->plan->create([
            'period' => $plan->interval === 'yearly' ? 'yearly' : 'monthly',
            'interval' => 1,
            'item' => [
                'name' => $plan->name,
                'amount' => (int) round($plan->price * 100), // paise
                'currency' => $plan->currency ?? 'INR',
                'description' => "Karigar {$plan->name} subscription",
            ],
        ]);

        return $razorpayPlan['id'];
    }

    /**
     * Create a one-time Razorpay order (money-in) for escrow funding.
     *
     * @return array<string, mixed>
     */
    public function createOrder(float $amount, string $receipt, string $currency = 'INR'): array
    {
        $order = $this->api()->order->create([
            'amount' => (int) round($amount * 100), // paise
            'currency' => $currency,
            'receipt' => $receipt,
            'payment_capture' => 1,
        ]);

        return $order->toArray();
    }

    /**
     * Create a Razorpay subscription for the given plan.
     *
     * @return array<string, mixed>
     */
    public function createSubscription(Plan $plan, int $totalCount = 12, ?string $offerId = null): array
    {
        $payload = [
            'plan_id' => $plan->razorpay_plan_id,
            'total_count' => $totalCount,
            'customer_notify' => 1,
        ];

        // A Razorpay Offer (created in the Dashboard) discounts the charged amount.
        if (! empty($offerId)) {
            $payload['offer_id'] = $offerId;
        }

        $subscription = $this->api()->subscription->create($payload);

        return $subscription->toArray();
    }

    /**
     * Verify the signature returned by Razorpay Checkout after authorization.
     *
     * @param  array<string, string>  $attributes
     */
    public function verifyPaymentSignature(array $attributes): bool
    {
        try {
            $this->api()->utility->verifyPaymentSignature($attributes);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Verify an incoming webhook payload against the configured webhook secret.
     */
    public function verifyWebhookSignature(string $body, string $signature): bool
    {
        $secret = config('services.razorpay.webhook_secret');

        if (empty($secret)) {
            return false;
        }

        try {
            $this->api()->utility->verifyWebhookSignature($body, $signature, $secret);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
