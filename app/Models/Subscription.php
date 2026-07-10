<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
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
            'invoiced_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Mark the subscription paid/active and issue its tax invoice number.
     */
    public function activateWithInvoice(): void
    {
        $this->fill([
            'status' => SubscriptionStatus::Active,
            'starts_at' => $this->starts_at ?? now(),
            'ends_at' => $this->plan->interval === 'yearly' ? now()->addYear() : now()->addMonth(),
        ]);

        if ($this->invoice_number === null) {
            $this->invoice_number = sprintf(
                '%s-%s-%05d',
                config('billing.invoice_prefix', 'KRG'),
                now()->format('Y'),
                $this->id,
            );
            $this->invoiced_at = now();
        }

        $this->save();
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
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
