<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\Gst;
use App\Services\Billing\SubscriptionCheckout;
use App\Services\ContactUnlocks;
use App\Services\JobPostingGate;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Plans" for the employer app — the job and database plan catalogue and the
 * Razorpay checkout hand-off. Nothing is sold as credits: contact unlocks come
 * with plans only. Mirrors the web SubscriptionController
 * but returns the raw values the mobile Razorpay SDK needs.
 */
class BillingController extends Controller
{
    /**
     * Plans, current subscriptions, unlock allowances and past invoices.
     */
    public function index(Request $request, RazorpayService $razorpay): JsonResponse
    {
        $account = $request->user()->employerAccount();
        $current = $account->activeSubscription();
        $currentDatabase = $account->activeSubscription(Plan::TYPE_DATABASE);

        return response()->json([
            'unlocks' => ContactUnlocks::for($account)->summary(),
            // The "Worker Database" card; "Buy Database" opens the database plans.
            'database' => ContactUnlocks::for($account)->database(),
            'plans' => Plan::where('is_active', true)->orderBy('price')->get()->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                // job: posts jobs and shows applicants; database: the Worker
                // Database only. One of each can run at the same time.
                'type' => $plan->type,
                // Before GST; `price_with_gst` is what the employer pays.
                'price' => (float) $plan->price,
                'gst_amount' => round($plan->grossPrice() - (float) $plan->price, 2),
                'price_with_gst' => $plan->grossPrice(),
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                // The raw limits, for logic. Show `feature_list` to the user:
                // `features` is keys and numbers, and rendered as-is it reads
                // "job post limit" with no number.
                'features' => $plan->features ?? [],
                'feature_list' => $plan->featureList(),
                'recommended' => $plan->isRecommended(),
                'is_current' => in_array($plan->id, [$current?->plan_id, $currentDatabase?->plan_id], true),
                // Razorpay plans are created on demand at checkout now.
                'purchasable' => $razorpay->configured(),
            ]),
            'current' => $current ? [
                'id' => $current->id,
                'plan' => $current->plan->name,
                'status' => $current->status->value,
                'starts_at' => $current->starts_at?->toIso8601String(),
                'ends_at' => $current->ends_at?->toIso8601String(),
            ] : null,
            'current_database' => $currentDatabase ? [
                'id' => $currentDatabase->id,
                'plan' => $currentDatabase->plan->name,
                'status' => $currentDatabase->status->value,
                'starts_at' => $currentDatabase->starts_at?->toIso8601String(),
                'ends_at' => $currentDatabase->ends_at?->toIso8601String(),
            ] : null,
            // Paid for a job plan before and holds none now: jobs are paused
            // and applicants hidden until it renews.
            'job_plan_lapsed' => $account->jobPlanLapsed(),
            // Job posts used in the current billing period; null without a plan.
            'job_posts' => JobPostingGate::usage($account),
            // One per payment, renewals included, newest first.
            'invoices' => $account->invoices()
                ->latest('issued_at')
                ->latest('id')
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                    'plan' => $invoice->plan_name,
                    // 1 is the first payment, 2 onwards renewals.
                    'cycle' => $invoice->cycle,
                    'total' => (float) $invoice->total_amount,
                    'date' => $invoice->issued_at->timezone(config('app.display_timezone'))->format('d M Y'),
                    // Invoice data the app renders itself; `web_url` is the
                    // printable session page, for opening in a browser.
                    'url' => route('api.employer.invoices.show', $invoice),
                    'web_url' => route('invoices.show', $invoice),
                ]),
            // Where invoice emails go; null means the employer gets none
            // until they add one (send `email` with subscribe, or on the profile).
            'billing_email' => $account->contactEmail(),
            'payment' => [
                'configured' => $razorpay->configured(),
                'key' => config('services.razorpay.key'),
                'gst_percent' => Gst::percent(),
            ],
        ]);
    }

    /**
     * Start a subscription: creates the Razorpay subscription and returns the
     * ids the app hands to the Razorpay checkout SDK.
     */
    public function subscribe(Request $request, Plan $plan, RazorpayService $razorpay, SubscriptionCheckout $checkout): JsonResponse
    {
        $account = $request->user()->employerAccount();
        abort_unless($request->user()->id === $account->id, 403, __('Only the account owner can change the plan.'));

        if (! $razorpay->configured()) {
            return response()->json([
                'message' => __('Payments are not configured yet. Please try again later.'),
            ], 422);
        }

        // `email`: where the GST invoice goes. Optional here so older app
        // builds keep working; without it a phone-OTP employer gets no
        // invoice email (see `billing_email` on GET /employer/plans).
        $data = $request->validate([
            'coupon' => ['nullable', 'string', 'max:60'],
            'email' => SubscriptionCheckout::emailRules($account),
        ]);

        SubscriptionCheckout::saveBillingEmail($account, $data['email'] ?? null);

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

        try {
            $subscription = $checkout->start($account, $plan, $coupon, $discount);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => __('Could not start the payment. Please try again in a few minutes.'),
                'code' => 'checkout_failed',
            ], 502);
        }

        return response()->json([
            'subscription_id' => $subscription->id,
            'razorpay_subscription_id' => $subscription->razorpay_subscription_id,
            'razorpay_key' => config('services.razorpay.key'),
            'plan' => ['id' => $plan->id, 'name' => $plan->name, 'price' => (float) $plan->price],
            'amounts' => SubscriptionCheckout::amounts($subscription),
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

        $subscription->activateWithInvoice($data['razorpay_payment_id']);
        $this->recordRedemption($subscription);

        return response()->json([
            'message' => __('Subscription activated!'),
            'unlocks' => ContactUnlocks::for($account)->summary(),
            'database' => ContactUnlocks::for($account)->database(),
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
