<?php

namespace App\Models;

use App\Services\Billing\InvoiceNumber;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A tax invoice for one payment on a subscription: the first payment is
 * cycle 1, each Razorpay renewal the next cycle. The amounts are a snapshot
 * of that payment, so later price or GST changes never rewrite it.
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property int|null $ai_credit_purchase_id
 * @property int $employer_id
 * @property int $cycle
 * @property string $number
 * @property string|null $financial_year
 * @property int|null $sequence
 * @property Carbon $issued_at
 * @property string|null $razorpay_payment_id
 * @property string $plan_name
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 */
class Invoice extends Model
{
    protected $fillable = [
        'subscription_id', 'ai_credit_purchase_id', 'employer_id', 'cycle', 'number', 'financial_year', 'sequence',
        'issued_at', 'razorpay_payment_id', 'plan_name', 'period_start', 'period_end',
        'discount_amount', 'subtotal_amount', 'gst_percent', 'gst_amount',
        'cgst_amount', 'sgst_amount', 'igst_amount', 'total_amount',
        'place_of_supply', 'seller_gstin', 'sac_code',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'discount_amount' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'gst_percent' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * Issue the invoice for one cycle of a subscription, unless that cycle has
     * one already. The payment callback and Razorpay's webhooks all report the
     * same payment, in any order and sometimes at once; the subscription row
     * is locked while this runs, so exactly one of them issues it.
     *
     * Returns the new invoice, or null when the cycle was already invoiced.
     * A payment id seen for the first time on an existing invoice is recorded.
     *
     * @param  array<string, mixed>  $amounts  invoice amount columns, see Gst::invoiceColumns()
     */
    public static function issue(
        Subscription $subscription,
        int $cycle,
        array $amounts,
        ?string $paymentId = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
    ): ?self {
        return DB::transaction(function () use ($subscription, $cycle, $amounts, $paymentId, $periodStart, $periodEnd) {
            Subscription::whereKey($subscription->id)->lockForUpdate()->first();

            $existing = static::where('subscription_id', $subscription->id)->where('cycle', $cycle)->first();

            if ($existing !== null) {
                if ($paymentId !== null && $existing->razorpay_payment_id === null) {
                    $existing->update(['razorpay_payment_id' => $paymentId]);
                }

                return null;
            }

            // The same payment reported again under another cycle number.
            if ($paymentId !== null && static::where('razorpay_payment_id', $paymentId)->exists()) {
                return null;
            }

            $issuedAt = now();

            return static::create([
                ...InvoiceNumber::next($issuedAt),
                ...$amounts,
                'subscription_id' => $subscription->id,
                'employer_id' => $subscription->employer_id,
                'cycle' => $cycle,
                'issued_at' => $issuedAt,
                'razorpay_payment_id' => $paymentId,
                'plan_name' => $subscription->plan->name,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);
        });
    }

    /**
     * Issue the invoice for a paid AI credit recharge, unless it has one. The
     * purchase row is locked, so the payment callback and the webhook racing
     * each other issue exactly one.
     */
    public static function issueForPurchase(AiCreditPurchase $purchase): ?self
    {
        return DB::transaction(function () use ($purchase) {
            AiCreditPurchase::whereKey($purchase->id)->lockForUpdate()->first();

            if (static::where('ai_credit_purchase_id', $purchase->id)->exists()) {
                return null;
            }

            $issuedAt = now();

            return static::create([
                ...InvoiceNumber::next($issuedAt),
                'discount_amount' => null,
                'subtotal_amount' => (float) $purchase->subtotal_amount,
                'gst_percent' => (float) $purchase->gst_percent,
                'gst_amount' => (float) $purchase->gst_amount,
                'cgst_amount' => $purchase->cgst_amount !== null ? (float) $purchase->cgst_amount : null,
                'sgst_amount' => $purchase->sgst_amount !== null ? (float) $purchase->sgst_amount : null,
                'igst_amount' => $purchase->igst_amount !== null ? (float) $purchase->igst_amount : null,
                'total_amount' => (float) $purchase->total_amount,
                'place_of_supply' => $purchase->place_of_supply,
                'seller_gstin' => $purchase->seller_gstin,
                'sac_code' => $purchase->sac_code,
                'ai_credit_purchase_id' => $purchase->id,
                'employer_id' => $purchase->employer_id,
                'cycle' => 1,
                'issued_at' => $issuedAt,
                'razorpay_payment_id' => $purchase->razorpay_payment_id,
                'plan_name' => __('AI call credits — :pack pack', ['pack' => $purchase->pack_name]),
            ]);
        });
    }

    /**
     * The PDF's file name. Slashes in the number cannot go in a file name.
     */
    public function filename(): string
    {
        return 'Invoice-'.str_replace('/', '-', $this->number).'.pdf';
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The AI credit recharge this invoice is for; null on a plan invoice.
     *
     * @return BelongsTo<AiCreditPurchase, $this>
     */
    public function aiCreditPurchase(): BelongsTo
    {
        return $this->belongsTo(AiCreditPurchase::class);
    }

    public function isAiCreditRecharge(): bool
    {
        return $this->ai_credit_purchase_id !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }
}
