<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * An employer's orders: every plan checkout they started, whether it was paid,
 * and the payments (with their tax invoices) made on it. One order is one
 * Subscription row; its first payment and every renewal are its invoices.
 *
 * Shared by the web Plans page and GET /api/v1/employer/orders, so both show
 * the same statuses.
 */
class OrderHistory
{
    /**
     * A checkout opened this long ago and still unpaid counts as not
     * completed: Razorpay's checkout was closed or the payment failed.
     */
    public const ABANDONED_AFTER_MINUTES = 30;

    /**
     * @return list<array<string, mixed>>
     */
    public static function for(User $account, bool $api = false): array
    {
        return $account->subscriptions()
            ->with(['plan', 'coupon', 'invoices' => fn ($q) => $q->orderBy('cycle')])
            ->latest('id')
            ->get()
            ->map(fn (Subscription $order) => self::row($order, $api))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Subscription $order, bool $api): array
    {
        $payment = self::paymentStatus($order);
        $plan = self::planStatus($order, $payment);
        $tz = config('app.display_timezone');

        return [
            'id' => $order->id,
            'order_number' => 'ORD-'.str_pad((string) $order->id, 5, '0', STR_PAD_LEFT),
            'plan' => $order->plan?->name,
            'plan_type' => $order->plan?->type,
            'interval' => $order->plan?->interval,
            'ordered_at' => $order->created_at?->toIso8601String(),
            'ordered_label' => $order->created_at?->timezone($tz)->format('d M Y, h:i A'),
            // What the first payment was for: after the coupon, with GST.
            'amount' => (float) ($order->total_amount ?? $order->plan?->grossPrice() ?? 0),
            'discount' => (float) ($order->discount_amount ?? 0),
            'coupon' => $order->coupon?->code,
            // paid | pending | not_completed | renewal_failed
            'payment_status' => $payment,
            'payment_label' => self::paymentLabel($payment),
            // active | expired | cancelled | completed | none
            'plan_status' => $plan,
            'plan_label' => self::planLabel($plan),
            'starts_at' => $order->starts_at?->toIso8601String(),
            'ends_at' => $order->ends_at?->toIso8601String(),
            'period_label' => $order->starts_at && $order->ends_at
                ? $order->starts_at->timezone($tz)->format('d M Y').' – '.$order->ends_at->timezone($tz)->format('d M Y')
                : null,
            'total_paid' => round((float) $order->invoices->sum('total_amount'), 2),
            // Every payment on the order, first one then renewals, each with
            // its tax invoice.
            'payments' => $order->invoices->map(fn (Invoice $invoice) => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'cycle' => $invoice->cycle,
                'renewal' => $invoice->cycle > 1,
                'amount' => (float) $invoice->total_amount,
                'paid_at' => $invoice->issued_at->toIso8601String(),
                'paid_label' => $invoice->issued_at->timezone($tz)->format('d M Y'),
                'period_label' => $invoice->period_start && $invoice->period_end
                    ? $invoice->period_start->timezone($tz)->format('d M Y').' – '.$invoice->period_end->timezone($tz)->format('d M Y')
                    : null,
                ...($api ? [
                    'invoice_url' => route('api.employer.invoices.show', $invoice),
                    'pdf_url' => route('api.employer.invoices.pdf', $invoice),
                ] : []),
                'web_url' => route('invoices.show', $invoice),
                'web_pdf_url' => route('invoices.pdf', $invoice),
            ])->values()->all(),
        ];
    }

    private static function paymentStatus(Subscription $order): string
    {
        if ($order->status === SubscriptionStatus::Halted) {
            return 'renewal_failed';
        }

        if ($order->invoices->isNotEmpty() || $order->starts_at !== null) {
            return 'paid';
        }

        $openedAt = $order->created_at ?? Carbon::now();

        return $openedAt->lt(now()->subMinutes(self::ABANDONED_AFTER_MINUTES)) ? 'not_completed' : 'pending';
    }

    private static function planStatus(Subscription $order, string $payment): string
    {
        if ($payment !== 'paid' && $payment !== 'renewal_failed') {
            return 'none';
        }

        return match (true) {
            $order->status === SubscriptionStatus::Cancelled => 'cancelled',
            $order->status === SubscriptionStatus::Completed => 'completed',
            $order->status->isEntitled() && ($order->ends_at === null || $order->ends_at->isFuture()) => 'active',
            default => 'expired',
        };
    }

    public static function paymentLabel(string $status): string
    {
        return match ($status) {
            'paid' => __('Paid'),
            'pending' => __('Awaiting payment'),
            'not_completed' => __('Payment not completed'),
            'renewal_failed' => __('Renewal payment failed'),
            default => $status,
        };
    }

    public static function planLabel(string $status): string
    {
        return match ($status) {
            'active' => __('Active'),
            'expired' => __('Expired'),
            'cancelled' => __('Cancelled'),
            'completed' => __('Completed'),
            default => '—',
        };
    }
}
