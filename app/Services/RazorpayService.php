<?php

namespace App\Services;

use App\Models\Plan;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\BadRequestError;
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
     * The amount is the price with GST added. Razorpay charges a subscription
     * whatever its plan says, so a plan made at the bare price (as these used
     * to be) collected ₹499 while the invoice said ₹588.82.
     *
     * Razorpay `period` accepts daily|weekly|monthly|yearly; our local
     * `interval` (monthly|yearly) maps straight across with interval count 1.
     */
    public function createPlan(Plan $plan): string
    {
        $gross = $plan->grossPrice();

        $razorpayPlan = $this->api()->plan->create([
            'period' => $plan->interval === 'yearly' ? 'yearly' : 'monthly',
            'interval' => 1,
            'item' => [
                'name' => $plan->name,
                'amount' => (int) round($gross * 100), // paise
                'currency' => $plan->currency ?? 'INR',
                'description' => "Super Karigar {$plan->name} subscription (incl. GST)",
            ],
        ]);

        $plan->update(['razorpay_plan_id' => $razorpayPlan['id'], 'razorpay_amount' => $gross]);

        return $razorpayPlan['id'];
    }

    /**
     * Make sure the plan's Razorpay plan charges today's price with today's
     * GST, creating a new one if not. Razorpay plans cannot be edited, so a
     * changed price or rate means a new plan; subscriptions already running on
     * the old one keep it, new ones get this.
     */
    public function ensurePlan(Plan $plan): void
    {
        if (! $plan->razorpayPlanIsCurrent()) {
            $this->createPlan($plan);
        }
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

        try {
            $subscription = $this->api()->subscription->create($payload);
        } catch (BadRequestError $e) {
            // A plan id saved under other keys (a test → live switch, or
            // another environment on this database) does not exist in this
            // Razorpay account: make the plan here and try once more.
            if (! str_contains($e->getMessage(), 'could not be found')) {
                throw $e;
            }

            $payload['plan_id'] = $this->createPlan($plan);
            $subscription = $this->api()->subscription->create($payload);
        }

        return $subscription->toArray();
    }

    /**
     * Stop a subscription renewing. At cycle end the employer keeps the days
     * already paid for; Razorpay just never charges it again.
     */
    public function cancelSubscription(string $subscriptionId, bool $atCycleEnd = true): void
    {
        $this->api()->subscription->fetch($subscriptionId)->cancel(['cancel_at_cycle_end' => $atCycleEnd ? 1 : 0]);
    }

    /**
     * Every payment Razorpay has taken on a subscription, oldest first. The
     * position is the cycle: the first is the checkout payment, the rest are
     * renewals. Read from the invoices Razorpay raises for each charge.
     *
     * @return list<array{payment_id: ?string, amount: float, paid_at: ?int, start: ?int, end: ?int}>
     */
    public function subscriptionCharges(string $subscriptionId): array
    {
        $items = $this->api()->invoice->all(['subscription_id' => $subscriptionId, 'count' => 100])->toArray()['items'] ?? [];

        return collect($items)
            ->filter(fn (array $invoice) => ($invoice['status'] ?? null) === 'paid')
            ->sortBy(fn (array $invoice) => [$invoice['paid_at'] ?? 0, $invoice['billing_start'] ?? 0])
            ->map(fn (array $invoice) => [
                'payment_id' => $invoice['payment_id'] ?? null,
                'amount' => ((int) ($invoice['amount_paid'] ?? $invoice['amount'] ?? 0)) / 100,
                'paid_at' => $invoice['paid_at'] ?? null,
                'start' => $invoice['billing_start'] ?? null,
                'end' => $invoice['billing_end'] ?? null,
            ])
            ->values()
            ->all();
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
