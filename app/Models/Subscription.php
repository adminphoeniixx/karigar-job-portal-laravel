<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Services\Billing\Gst;
use App\Services\Billing\InvoiceDocument;
use App\Services\RazorpayService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @property int $id
 * @property int $employer_id
 * @property int $plan_id
 * @property string|null $razorpay_subscription_id
 * @property string|null $razorpay_customer_id
 * @property SubscriptionStatus $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $reminded_for
 * @property Carbon|null $payment_failed_for
 * @property Carbon|null $lapse_notified_for
 */
class Subscription extends Model
{
    protected $fillable = [
        'employer_id', 'plan_id', 'coupon_id', 'discount_amount',
        'subtotal_amount', 'gst_percent', 'gst_amount', 'total_amount',
        'cgst_amount', 'sgst_amount', 'igst_amount', 'place_of_supply', 'seller_gstin', 'sac_code',
        // The single invoice a subscription carried before the invoices table;
        // only old rows have them, and billing:backfill-invoices copies them over.
        'invoice_number', 'invoiced_at',
        'razorpay_subscription_id', 'razorpay_customer_id',
        'status', 'starts_at', 'ends_at',
        // The ends_at each notice of App\Services\Billing\PlanReminders was sent for.
        'reminded_for', 'payment_failed_for', 'lapse_notified_for',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'discount_amount' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'gst_percent' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'invoiced_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminded_for' => 'datetime',
            'payment_failed_for' => 'datetime',
            'lapse_notified_for' => 'datetime',
        ];
    }

    /**
     * The first payment landed: mark the subscription active and issue its
     * first tax invoice.
     *
     * The app's payment callback and Razorpay's webhooks all call this for the
     * same payment, in any order; only the first one issues the invoice, so
     * the email goes once and an older plan is retired once.
     */
    public function activateWithInvoice(?string $paymentId = null): void
    {
        $this->fill([
            'status' => SubscriptionStatus::Active,
            'starts_at' => $this->starts_at ?? now(),
            'ends_at' => $this->plan->periodEnd(now()),
        ]);

        $this->save();

        $invoice = Invoice::issue(
            $this,
            cycle: 1,
            amounts: Gst::invoiceColumns($this),
            paymentId: $paymentId,
            periodStart: $this->starts_at,
            periodEnd: $this->ends_at,
        );

        if ($invoice !== null) {
            InvoiceDocument::for($invoice)->email();
            $this->retireOlderPlans();
        }

        // A renewed job plan puts the employer's paused jobs back in search.
        if (! $this->plan->isDatabase()) {
            JobListing::syncSearchFor($this->employer);
        }
    }

    /**
     * Razorpay charged a renewal: carry the plan on to the end of the new
     * cycle and invoice that payment. `$cycle` is Razorpay's paid_count, so a
     * webhook delivered twice invoices the payment once.
     */
    public function renew(
        int $cycle,
        ?string $paymentId = null,
        ?float $amountPaid = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
    ): void {
        $periodStart ??= $this->ends_at ?? now();
        $periodEnd ??= $this->plan->periodEnd($periodStart);

        $this->update([
            'status' => SubscriptionStatus::Active,
            'ends_at' => $periodEnd,
        ]);

        $invoice = Invoice::issue(
            $this,
            cycle: $cycle,
            amounts: Gst::invoiceColumns($this, $amountPaid),
            paymentId: $paymentId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );

        $invoice && InvoiceDocument::for($invoice)->email();

        if (! $this->plan->isDatabase()) {
            JobListing::syncSearchFor($this->employer);
        }
    }

    /**
     * Switching plans: the account holds one plan of each type, so a plan
     * bought while another of its type still runs replaces it. The old one
     * is stopped at Razorpay, or it would keep charging every month next to
     * the new one.
     */
    private function retireOlderPlans(): void
    {
        $older = static::where('employer_id', $this->employer_id)
            ->whereKeyNot($this->id)
            ->entitled()
            ->ofType($this->plan->type)
            ->get();

        foreach ($older as $subscription) {
            if ($subscription->razorpay_subscription_id) {
                try {
                    app(RazorpayService::class)->cancelSubscription($subscription->razorpay_subscription_id);
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $subscription->update(['status' => SubscriptionStatus::Cancelled]);
        }
    }

    /**
     * Subscriptions that grant access right now.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeEntitled(Builder $query): void
    {
        $query->whereIn('status', array_map(fn ($s) => $s->value, SubscriptionStatus::entitled()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * Subscriptions to one kind of plan ({@see Plan::TYPE_JOB} or {@see Plan::TYPE_DATABASE}).
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->whereHas('plan', fn (Builder $p) => $p->where('type', $type));
    }

    /**
     * One per payment: the first, then each renewal.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * When the billing period now running began. A renewal moves ends_at on
     * (the Razorpay webhook sets it to the cycle's end), so the cycle is the
     * one interval before it. Per-period limits count from here.
     */
    public function currentCycleStart(): CarbonInterface
    {
        if ($this->ends_at !== null) {
            $start = $this->plan->periodStart($this->ends_at);

            // Never before the subscription itself began.
            return $this->starts_at !== null && $this->starts_at->gt($start) ? $this->starts_at : $start;
        }

        return $this->starts_at ?? $this->created_at;
    }

    /**
     * Whether Razorpay will charge the next cycle by itself: a live
     * subscription that has not used up its cycles. One retired for a newer
     * plan is cancelled, so it does not count.
     */
    public function willRenew(): bool
    {
        return $this->razorpay_subscription_id !== null
            && $this->status === SubscriptionStatus::Active
            && (int) ($this->invoices()->max('cycle') ?? 1) < RazorpayService::SUBSCRIPTION_CYCLES;
    }

    /**
     * What the next renewal charges, GST included. A coupon took money off
     * the first payment only, so a discounted plan renews at its full price.
     */
    public function renewalAmount(): float
    {
        return $this->coupon_id !== null && (float) $this->discount_amount > 0
            ? Gst::gross((float) $this->subtotal_amount + (float) $this->discount_amount)
            : (float) $this->total_amount;
    }

    public function isActive(): bool
    {
        return $this->status->isEntitled()
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
