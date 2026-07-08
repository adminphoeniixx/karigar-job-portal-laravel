<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RazorpayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function pricing(Request $request): Response
    {
        return Inertia::render('subscription/Pricing', [
            'plans' => Plan::where('is_active', true)->orderBy('price')->get(),
            'current' => $request->user()->activeSubscription()?->load('plan'),
            'razorpayConfigured' => app(RazorpayService::class)->configured(),
            // Partial-reloaded when the employer applies a coupon (?coupon=CODE).
            'couponResult' => $this->previewCoupon($request->query('coupon'), $request->user()),
        ]);
    }

    public function subscribe(Plan $plan, Request $request, RazorpayService $razorpay): RedirectResponse|Response
    {
        if (! $razorpay->configured() || empty($plan->razorpay_plan_id)) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => __('Payments are not configured yet. Please try again later.'),
            ]);
        }

        $data = $request->validate([
            'coupon' => ['nullable', 'string', 'max:60'],
        ]);

        // Resolve + fully validate any coupon for this exact plan.
        $coupon = null;
        $discount = 0.0;

        if (! empty($data['coupon'])) {
            $coupon = $this->findCoupon($data['coupon']);
            $reason = $coupon?->reasonInvalidFor($request->user(), $plan, (float) $plan->price);

            if (! $coupon || $reason !== null) {
                return back()->with('toast', [
                    'type' => 'error',
                    'message' => $reason ?? __('Invalid coupon code.'),
                ]);
            }

            $discount = $coupon->discountFor((float) $plan->price);
        }

        $remote = $razorpay->createSubscription($plan, offerId: $coupon?->razorpay_offer_id);

        $subscription = $request->user()->subscriptions()->create([
            'plan_id' => $plan->id,
            'coupon_id' => $coupon?->id,
            'discount_amount' => $coupon ? $discount : null,
            'razorpay_subscription_id' => $remote['id'],
            'status' => SubscriptionStatus::Created,
        ]);

        return Inertia::render('subscription/Checkout', [
            'razorpayKey' => config('services.razorpay.key'),
            'subscriptionId' => $subscription->razorpay_subscription_id,
            'plan' => $plan,
            'discountAmount' => $coupon ? $discount : null,
        ]);
    }

    public function callback(Request $request, RazorpayService $razorpay): RedirectResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_subscription_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $verified = $razorpay->verifyPaymentSignature($data);

        $subscription = Subscription::where('razorpay_subscription_id', $data['razorpay_subscription_id'])
            ->where('employer_id', $request->user()->id)
            ->firstOrFail();

        if (! $verified) {
            return to_route('subscription.pricing')->with('toast', [
                'type' => 'error',
                'message' => __('Payment verification failed.'),
            ]);
        }

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at' => $subscription->plan->interval === 'yearly' ? now()->addYear() : now()->addMonth(),
        ]);

        $this->recordRedemption($subscription);

        return to_route('subscription.pricing')->with('toast', [
            'type' => 'success',
            'message' => __('Subscription activated!'),
        ]);
    }

    /**
     * Record a coupon redemption once (on payment) and bump the usage counter.
     */
    private function recordRedemption(Subscription $subscription): void
    {
        if (! $subscription->coupon_id) {
            return;
        }

        DB::transaction(function () use ($subscription) {
            $exists = $subscription->coupon
                ->redemptions()
                ->where('subscription_id', $subscription->id)
                ->exists();

            if ($exists) {
                return;
            }

            $subscription->coupon->redemptions()->create([
                'user_id' => $subscription->employer_id,
                'subscription_id' => $subscription->id,
                'discount_amount' => $subscription->discount_amount ?? 0,
            ]);

            $subscription->coupon->increment('redeemed_count');
        });
    }

    /**
     * Build the coupon preview shown on the pricing page. Plan-specific checks
     * (eligibility, minimum amount) are applied per-plan on the client; here we
     * only surface the coupon's shape + its plan-agnostic validity.
     *
     * @return array<string, mixed>|null
     */
    private function previewCoupon(?string $code, User $user): ?array
    {
        if (blank($code)) {
            return null;
        }

        $coupon = $this->findCoupon($code);

        if (! $coupon) {
            return ['code' => strtoupper(trim($code)), 'valid' => false, 'message' => __('Invalid coupon code.')];
        }

        $reason = $coupon->globalReasonInvalid($user);

        return [
            'code' => $coupon->code,
            'valid' => $reason === null,
            'message' => $reason,
            'discount_type' => $coupon->discount_type->value,
            'discount_value' => (float) $coupon->discount_value,
            'max_discount_amount' => $coupon->max_discount_amount !== null ? (float) $coupon->max_discount_amount : null,
            'min_amount' => $coupon->min_amount !== null ? (float) $coupon->min_amount : null,
            'plan_ids' => $coupon->plan_ids ?: [],
        ];
    }

    private function findCoupon(string $code): ?Coupon
    {
        return Coupon::whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->first();
    }
}
