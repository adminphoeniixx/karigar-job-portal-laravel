<?php

namespace App\Services\Billing;

use App\Models\AiCreditPack;
use App\Models\AiCreditPurchase;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AiCredits;
use App\Services\RazorpayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Buying AI call credits: a one-time Razorpay order for a pack, with GST on
 * top, and the credits and the tax invoice once it is paid. The web page, the
 * app's API and the Razorpay webhook all go through here, so a payment is
 * credited once however many of them report it.
 */
class AiCreditCheckout
{
    public function __construct(private RazorpayService $razorpay) {}

    public function start(User $account, AiCreditPack $pack, ?User $by = null): AiCreditPurchase
    {
        $quote = Gst::quote((float) $pack->price, $account->employerProfile);

        // Razorpay caps a receipt at 40 characters.
        $order = $this->razorpay->createOrder($quote['total'], 'aic_'.$account->id.'_'.Str::lower(Str::random(10)));

        return AiCreditPurchase::create([
            'employer_id' => $account->id,
            'ai_credit_pack_id' => $pack->id,
            'pack_name' => $pack->name,
            'credits' => $pack->credits,
            'bonus_credits' => $pack->bonus_credits,
            'status' => AiCreditPurchase::STATUS_CREATED,
            'razorpay_order_id' => $order['id'],
            ...Gst::subscriptionColumns($quote),
            'created_by' => $by?->id,
        ]);
    }

    /**
     * Mark a purchase paid and credit it. Safe to call again for the same
     * payment: the credits and the invoice are each added once.
     *
     * Returns true the first time, false when it was already paid.
     */
    public function confirm(AiCreditPurchase $purchase, ?string $paymentId): bool
    {
        $first = DB::transaction(function () use ($purchase, $paymentId) {
            $locked = AiCreditPurchase::whereKey($purchase->id)->lockForUpdate()->first();

            if ($locked->isPaid()) {
                return false;
            }

            $locked->update([
                'status' => AiCreditPurchase::STATUS_PAID,
                'razorpay_payment_id' => $paymentId,
                'paid_at' => now(),
            ]);

            return true;
        });

        $purchase->refresh();

        // Each of these is idempotent on its own, so a run that died half
        // way through is finished by the next report of the same payment.
        AiCredits::for($purchase->employer)->creditPurchase($purchase);

        $invoice = Invoice::issueForPurchase($purchase);

        if ($invoice !== null) {
            try {
                InvoiceDocument::for($invoice)->email();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $first;
    }

    /**
     * What the checkout screen shows for a purchase it just opened.
     *
     * @return array<string, mixed>
     */
    public static function amounts(AiCreditPurchase $purchase): array
    {
        return [
            'subtotal' => (float) $purchase->subtotal_amount,
            'gst_percent' => (float) $purchase->gst_percent,
            'gst' => (float) $purchase->gst_amount,
            'cgst' => (float) $purchase->cgst_amount,
            'sgst' => (float) $purchase->sgst_amount,
            'igst' => (float) $purchase->igst_amount,
            'place_of_supply' => $purchase->place_of_supply,
            'total' => (float) $purchase->total_amount,
        ];
    }
}
