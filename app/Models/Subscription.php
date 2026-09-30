<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Services\Billing\InvoiceDocument;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employer_id
 * @property int $plan_id
 * @property string|null $razorpay_subscription_id
 * @property string|null $razorpay_customer_id
 * @property SubscriptionStatus $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
class Subscription extends Model
{
    protected $fillable = [
        'employer_id', 'plan_id', 'coupon_id', 'discount_amount',
        'subtotal_amount', 'gst_percent', 'gst_amount', 'total_amount',
        'cgst_amount', 'sgst_amount', 'igst_amount', 'place_of_supply', 'seller_gstin', 'sac_code',
        'invoice_number', 'invoiced_at',
        'razorpay_subscription_id', 'razorpay_customer_id',
        'status', 'starts_at', 'ends_at',
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
        ];
    }

    /**
     * Mark the subscription paid/active and issue its tax invoice number.
     *
     * The app's payment callback and Razorpay's webhook both call this for the
     * same payment, in either order; the invoice is issued, and emailed, only
     * by whichever gets here first.
     */
    public function activateWithInvoice(): void
    {
        $this->fill([
            'status' => SubscriptionStatus::Active,
            'starts_at' => $this->starts_at ?? now(),
            'ends_at' => $this->plan->interval === 'yearly' ? now()->addYear() : now()->addMonth(),
        ]);

        $issuing = false;

        if ($this->invoice_number === null) {
            $number = sprintf(
                '%s-%s-%05d',
                config('billing.invoice_prefix', 'KRG'),
                now()->format('Y'),
                $this->id,
            );

            // Claimed with a conditional update rather than read-then-write, so
            // a callback and a webhook racing each other cannot both issue it.
            $issuing = static::whereKey($this->id)
                ->whereNull('invoice_number')
                ->update(['invoice_number' => $number, 'invoiced_at' => now()]) === 1;

            $this->invoice_number = $number;
            $this->invoiced_at ??= now();
        }

        $this->save();

        if ($issuing) {
            InvoiceDocument::for($this)->email();
        }

        // A renewed job plan puts the employer's paused jobs back in search.
        if (! $this->plan->isDatabase()) {
            JobListing::syncSearchFor($this->employer);
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
            $start = $this->plan->interval === 'yearly'
                ? $this->ends_at->copy()->subYear()
                : $this->ends_at->copy()->subMonth();

            // Never before the subscription itself began.
            return $this->starts_at !== null && $this->starts_at->gt($start) ? $this->starts_at : $start;
        }

        return $this->starts_at ?? $this->created_at;
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
