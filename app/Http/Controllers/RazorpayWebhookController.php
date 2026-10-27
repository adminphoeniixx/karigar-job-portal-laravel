<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\AiCreditPurchase;
use App\Models\Subscription;
use App\Services\Billing\AiCreditCheckout;
use App\Services\Billing\PlanReminders;
use App\Services\RazorpayService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class RazorpayWebhookController extends Controller
{
    public function handle(Request $request, RazorpayService $razorpay, AiCreditCheckout $credits, PlanReminders $reminders): Response
    {
        $signature = $request->header('X-Razorpay-Signature', '');

        if (! $razorpay->verifyWebhookSignature($request->getContent(), $signature)) {
            return response('Invalid signature', 400);
        }

        $event = $request->input('event');

        // An AI credit recharge: a one-time order, not a subscription. This
        // credits it even when the employer closed the app before the
        // checkout's own callback reached us.
        if (in_array($event, ['payment.captured', 'order.paid'], true)) {
            $orderId = $request->input('payload.payment.entity.order_id') ?? $request->input('payload.order.entity.id');
            $purchase = $orderId ? AiCreditPurchase::where('razorpay_order_id', $orderId)->first() : null;

            if ($purchase !== null) {
                $credits->confirm($purchase, $request->input('payload.payment.entity.id') ?? $purchase->razorpay_payment_id);

                return response('ok', 200);
            }
        }

        $entity = $request->input('payload.subscription.entity', []);
        $razorpaySubId = $entity['id'] ?? null;

        if ($razorpaySubId === null) {
            return response('ignored', 200);
        }

        $subscription = Subscription::where('razorpay_subscription_id', $razorpaySubId)->first();

        if ($subscription === null) {
            return response('not found', 200);
        }

        $payment = $request->input('payload.payment.entity', []);
        $paymentId = $payment['id'] ?? null;
        // Razorpay counts the payments taken on the subscription; the first is 1.
        $paidCount = (int) ($entity['paid_count'] ?? 0);
        $time = fn (string $key) => isset($entity[$key]) ? Carbon::createFromTimestamp($entity[$key]) : null;

        match (true) {
            // A renewal: every charge after the first gets its own invoice.
            $event === 'subscription.charged' && $paidCount > 1 => $subscription->renew(
                cycle: $paidCount,
                paymentId: $paymentId,
                amountPaid: isset($payment['amount']) ? $payment['amount'] / 100 : null,
                periodStart: $time('current_start'),
                periodEnd: $time('current_end'),
            ),
            in_array($event, ['subscription.activated', 'subscription.charged', 'subscription.authenticated'], true) => $subscription->activateWithInvoice($paymentId, $time('current_end')),
            // A renewal charge failed; Razorpay tries again for a few days.
            $event === 'subscription.pending' => $reminders->paymentFailed(tap($subscription)->update(['status' => SubscriptionStatus::Pending])),
            // It stopped for good: all retries failed, it was cancelled, or its cycles ran out.
            $event === 'subscription.halted' => $reminders->lapsed(tap($subscription)->update(['status' => SubscriptionStatus::Halted])),
            $event === 'subscription.cancelled' => $reminders->lapsed(tap($subscription)->update(['status' => SubscriptionStatus::Cancelled])),
            $event === 'subscription.completed' => $reminders->lapsed(tap($subscription)->update(['status' => SubscriptionStatus::Completed])),
            default => null,
        };

        return response('ok', 200);
    }
}
