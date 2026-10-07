<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\RazorpayService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class RazorpayWebhookController extends Controller
{
    public function handle(Request $request, RazorpayService $razorpay): Response
    {
        $signature = $request->header('X-Razorpay-Signature', '');

        if (! $razorpay->verifyWebhookSignature($request->getContent(), $signature)) {
            return response('Invalid signature', 400);
        }

        $event = $request->input('event');
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
            in_array($event, ['subscription.activated', 'subscription.charged', 'subscription.authenticated'], true) => tap($subscription)->activateWithInvoice($paymentId)->update([
                'ends_at' => $time('current_end') ?? $subscription->ends_at,
            ]),
            $event === 'subscription.halted' => $subscription->update(['status' => SubscriptionStatus::Halted]),
            $event === 'subscription.cancelled' => $subscription->update(['status' => SubscriptionStatus::Cancelled]),
            $event === 'subscription.completed' => $subscription->update(['status' => SubscriptionStatus::Completed]),
            default => null,
        };

        return response('ok', 200);
    }
}
