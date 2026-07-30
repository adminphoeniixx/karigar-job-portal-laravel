<?php

namespace App\Http\Controllers\Api\Employer;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CreditPurchase;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\CreditWallet;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Credits & Plans" for the employer app — plan catalogue, Razorpay checkout
 * hand-off and one-time credit top-ups. Mirrors the web SubscriptionController
 * but returns the raw values the mobile Razorpay SDK needs.
 */
class BillingController extends Controller
{
    /**
     * Plans, current subscription, credit balance and past invoices.
     */
    public function index(Request $request, RazorpayService $razorpay): JsonResponse
    {
        $account = $request->user()->employerAccount();
        $current = $account->activeSubscription();

        return response()->json([
            'credits' => CreditWallet::for($account)->summary(),
            'plans' => Plan::where('is_active', true)->orderBy('price')->get()->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price' => (float) $plan->price,
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'features' => $plan->features ?? [],
                'is_current' => $current?->plan_id === $plan->id,
                'purchasable' => ! empty($plan->razorpay_plan_id),
            ]),
            'current' => $current ? [
                'id' => $current->id,
                'plan' => $current->plan->name,
                'status' => $current->status->value,
                'starts_at' => $current->starts_at?->toIso8601String(),
                'ends_at' => $current->ends_at?->toIso8601String(),
            ] : null,
            'credit_packs' => collect(config('billing.credit_packs'))
                ->map(fn (array $pack, string $key) => [
                    'key' => $key,
                    'credits' => $pack['credits'],
                    'price' => (float) $pack['price'],
                    'label' => $pack['label'],
                ])->values(),
            'boost_tiers' => collect(config('billing.boost_tiers'))
                ->map(fn (array $tier, string $key) => [
                    'key' => $key,
                    'credits' => $tier['credits'],
                    'days' => $tier['days'],
                    'label' => $tier['label'],
                ])->values(),
            'invoices' => $account->subscriptions()
                ->whereNotNull('invoice_number')
                ->with('plan:id,name')
                ->orderByDesc('invoiced_at')
                ->get()
                ->map(fn (Subscription $s) => [
                    'id' => $s->id,
                    'invoice_number' => $s->invoice_number,
                    'plan' => $s->plan->name,
                    'total' => (float) $s->total_amount,
                    'date' => $s->invoiced_at?->format('d M Y'),
                    'url' => route('subscription.invoice', $s),
                ]),
            'payment' => [
                'configured' => $razorpay->configured(),
                'key' => config('services.razorpay.key'),
                'gst_percent' => (float) config('billing.gst_percent'),
            ],
        ]);
    }

    /**
     * Start a subscription: creates the Razorpay subscription and returns the
     * ids the app hands to the Razorpay checkout SDK.
     */
    public function subscribe(Request $request, Plan $plan, RazorpayService $razorpay): JsonResponse
    {
        $account = $request->user()->employerAccount();
        abort_unless($request->user()->id === $account->id, 403, __('Only the account owner can change the plan.'));

        if (! $razorpay->configured() || empty($plan->razorpay_plan_id)) {
            return response()->json([
                'message' => __('Payments are not configured yet. Please try again later.'),
            ], 422);
        }

        $data = $request->validate(['coupon' => ['nullable', 'string', 'max:60']]);

        $coupon = null;
        $discount = 0.0;

        if (! empty($data['coupon'])) {
            $coupon = Coupon::whereRaw('UPPER(code) = ?', [strtoupper(trim($data['coupon']))])->first();
            $reason = $coupon?->reasonInvalidFor($account, $plan, (float) $plan->price);

            if (! $coupon || $reason !== null) {
                return response()->json(['message' => $reason ?? __('Invalid coupon code.')], 422);
            }

            $discount = $coupon->discountFor((float) $plan->price);
        }

        $remote = $razorpay->createSubscription($plan, offerId: $coupon?->razorpay_offer_id);

        $subtotal = round((float) $plan->price - $discount, 2);
        $gstPercent = (float) config('billing.gst_percent');
        $gstAmount = round($subtotal * $gstPercent / 100, 2);

        $subscription = $account->subscriptions()->create([
            'plan_id' => $plan->id,
            'coupon_id' => $coupon?->id,
            'discount_amount' => $coupon ? $discount : null,
            'subtotal_amount' => $subtotal,
            'gst_percent' => $gstPercent,
            'gst_amount' => $gstAmount,
            'total_amount' => round($subtotal + $gstAmount, 2),
            'razorpay_subscription_id' => $remote['id'],
            'status' => SubscriptionStatus::Created,
        ]);

        return response()->json([
            'subscription_id' => $subscription->id,
            'razorpay_subscription_id' => $subscription->razorpay_subscription_id,
            'razorpay_key' => config('services.razorpay.key'),
            'plan' => ['id' => $plan->id, 'name' => $plan->name, 'price' => (float) $plan->price],
            'amounts' => [
                'discount' => $coupon ? $discount : 0,
                'subtotal' => $subtotal,
                'gst_percent' => $gstPercent,
                'gst' => $gstAmount,
                'total' => (float) $subscription->total_amount,
            ],
        ], 201);
    }

    /**
     * Confirm a subscription payment made in the app's Razorpay checkout.
     */
    public function callback(Request $request, RazorpayService $razorpay): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_subscription_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $account = $request->user()->employerAccount();

        $subscription = Subscription::where('razorpay_subscription_id', $data['razorpay_subscription_id'])
            ->where('employer_id', $account->id)
            ->firstOrFail();

        if (! $razorpay->verifyPaymentSignature($data)) {
            return response()->json(['message' => __('Payment verification failed.')], 422);
        }

        $subscription->activateWithInvoice();
        $this->recordRedemption($subscription);

        return response()->json([
            'message' => __('Subscription activated!'),
            'credits' => CreditWallet::for($account)->summary(),
        ]);
    }

    /**
     * Buy a one-time credit top-up: returns a Razorpay order for the app.
     */
    public function topUp(Request $request, RazorpayService $razorpay): JsonResponse
    {
        $account = $request->user()->employerAccount();

        $data = $request->validate([
            'pack' => ['required', 'string', 'in:'.implode(',', array_keys(config('billing.credit_packs')))],
        ]);

        if (! $razorpay->configured()) {
            return response()->json([
                'message' => __('Payments are not configured yet. Please try again later.'),
            ], 422);
        }

        $pack = config("billing.credit_packs.{$data['pack']}");

        $purchase = CreditPurchase::create([
            'employer_id' => $account->id,
            'pack' => $data['pack'],
            'credits' => $pack['credits'],
            'amount' => $pack['price'],
        ]);

        $order = $razorpay->createOrder((float) $pack['price'], "credits-{$purchase->id}");
        $purchase->update(['razorpay_order_id' => $order['id']]);

        return response()->json([
            'purchase_id' => $purchase->id,
            'razorpay_order_id' => $order['id'],
            'razorpay_key' => config('services.razorpay.key'),
            'amount' => (float) $pack['price'],
            'credits' => $pack['credits'],
            'currency' => $order['currency'] ?? 'INR',
        ], 201);
    }

    /**
     * Confirm a top-up payment and credit the wallet.
     */
    public function topUpCallback(Request $request, RazorpayService $razorpay): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $account = $request->user()->employerAccount();

        $purchase = CreditPurchase::where('razorpay_order_id', $data['razorpay_order_id'])
            ->where('employer_id', $account->id)
            ->firstOrFail();

        if (! $razorpay->verifyPaymentSignature($data)) {
            return response()->json(['message' => __('Payment verification failed.')], 422);
        }

        if ($purchase->status !== 'paid') {
            DB::transaction(function () use ($purchase, $data, $account) {
                $purchase->update([
                    'status' => 'paid',
                    'razorpay_payment_id' => $data['razorpay_payment_id'],
                    'paid_at' => now(),
                ]);

                CreditWallet::for($account)->add($purchase->credits);
            });
        }

        return response()->json([
            'message' => trans_choice(':count credit added.|:count credits added.', $purchase->credits, ['count' => $purchase->credits]),
            'credits' => CreditWallet::for($account)->summary(),
        ]);
    }

    /**
     * Record a coupon redemption once, mirroring the web flow.
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
}
