<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tax invoice for a paid subscription, as data rather than a rendered page —
 * the app lays it out itself and can share or print from there.
 *
 * The web equivalent renders the identical fields into an Inertia page.
 *
 * @see \App\Http\Controllers\InvoiceController
 */
class InvoiceController extends Controller
{
    public function show(Request $request, Subscription $subscription): JsonResponse
    {
        // Team members bill under the owner's account, so compare against that
        // rather than the signed-in user.
        abort_unless($subscription->employer_id === $request->user()->employerAccount()->id, 403);
        abort_if($subscription->invoice_number === null, 404);

        $subscription->load('plan', 'coupon');
        $profile = $request->user()->employerAccount()->employerProfile;

        return response()->json([
            'invoice' => [
                'number' => $subscription->invoice_number,
                'date' => $subscription->invoiced_at?->format('d M Y'),
                'plan' => [
                    'name' => $subscription->plan->name,
                    'interval' => $subscription->plan->interval,
                    'price' => (float) $subscription->plan->price,
                ],
                'coupon_code' => $subscription->coupon?->code,
                'discount' => (float) $subscription->discount_amount,
                'subtotal' => (float) $subscription->subtotal_amount,
                'gst_percent' => (float) $subscription->gst_percent,
                'gst_amount' => (float) $subscription->gst_amount,
                'total' => (float) $subscription->total_amount,
                'period' => [
                    'from' => $subscription->starts_at?->format('d M Y'),
                    'to' => $subscription->ends_at?->format('d M Y'),
                ],
                'payment_ref' => $subscription->razorpay_subscription_id,
            ],
            'seller' => config('billing.seller'),
            'buyer' => [
                'name' => $profile?->company_name ?: $request->user()->employerAccount()->name,
                'address' => trim(implode(', ', array_filter([
                    $profile?->address, $profile?->city, $profile?->state,
                ]))),
                'gstin' => $profile?->gstin,
                'email' => $request->user()->employerAccount()->email,
                'phone' => $request->user()->employerAccount()->phone ?? $profile?->phone,
            ],
        ]);
    }
}
