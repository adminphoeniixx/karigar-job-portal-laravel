<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    /**
     * Printable tax invoice for a paid subscription.
     */
    public function show(Request $request, Subscription $subscription): Response
    {
        abort_unless($subscription->employer_id === $request->user()->id, 403);
        abort_if($subscription->invoice_number === null, 404);

        $subscription->load('plan', 'coupon');
        $profile = $request->user()->employerProfile;

        return Inertia::render('subscription/Invoice', [
            'invoice' => [
                'number' => $subscription->invoice_number,
                'date' => $subscription->invoiced_at?->format('d M Y'),
                'plan' => [
                    'name' => $subscription->plan->name,
                    'interval' => $subscription->plan->interval,
                    'price' => $subscription->plan->price,
                ],
                'coupon_code' => $subscription->coupon?->code,
                'discount' => $subscription->discount_amount,
                'subtotal' => $subscription->subtotal_amount,
                'gst_percent' => $subscription->gst_percent,
                'gst_amount' => $subscription->gst_amount,
                'total' => $subscription->total_amount,
                'period' => [
                    'from' => $subscription->starts_at?->format('d M Y'),
                    'to' => $subscription->ends_at?->format('d M Y'),
                ],
                'payment_ref' => $subscription->razorpay_subscription_id,
            ],
            'seller' => config('billing.seller'),
            'buyer' => [
                'name' => $profile?->company_name ?: $request->user()->name,
                'address' => trim(implode(', ', array_filter([
                    $profile?->address, $profile?->city, $profile?->state,
                ]))),
                'gstin' => $profile?->gstin,
                'email' => $request->user()->email,
                'phone' => $request->user()->phone ?? $profile?->phone,
            ],
        ]);
    }
}
