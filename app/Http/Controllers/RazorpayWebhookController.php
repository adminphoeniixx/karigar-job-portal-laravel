<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\RazorpayService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

        match ($event) {
            'subscription.activated', 'subscription.charged', 'subscription.authenticated' => tap($subscription)->activateWithInvoice()->update([
                'ends_at' => isset($entity['current_end'])
                    ? now()->createFromTimestamp($entity['current_end'])
                    : $subscription->ends_at,
            ]),
            'subscription.halted' => $subscription->update(['status' => SubscriptionStatus::Halted]),
            'subscription.cancelled' => $subscription->update(['status' => SubscriptionStatus::Cancelled]),
            'subscription.completed' => $subscription->update(['status' => SubscriptionStatus::Completed]),
            default => null,
        };

        return response('ok', 200);
    }
}
