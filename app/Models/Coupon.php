<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string|null $description
 * @property DiscountType $discount_type
 * @property string $discount_value
 * @property string|null $max_discount_amount
 * @property string|null $min_amount
 * @property string|null $razorpay_offer_id
 * @property array<int, int>|null $plan_ids
 * @property int|null $max_redemptions
 * @property int $redeemed_count
 * @property int $per_user_limit
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property bool $is_active
 */
class Coupon extends Model
{
    protected $fillable = [
        'code', 'description', 'discount_type', 'discount_value', 'max_discount_amount',
        'min_amount', 'razorpay_offer_id', 'plan_ids', 'max_redemptions',
        'redeemed_count', 'per_user_limit', 'starts_at', 'expires_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'plan_ids' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function appliesToPlan(Plan $plan): bool
    {
        return empty($this->plan_ids) || in_array($plan->id, $this->plan_ids, true);
    }

    /**
     * Plan-agnostic validity (active, date window, usage + per-user limits).
     * Returns a human-readable reason it can't be used, or null if valid so far.
     */
    public function globalReasonInvalid(User $user): ?string
    {
        if (! $this->is_active) {
            return __('This coupon is no longer active.');
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return __('This coupon is not active yet.');
        }

        if ($this->expires_at && $now->gt($this->expires_at)) {
            return __('This coupon has expired.');
        }

        if ($this->max_redemptions !== null && $this->redeemed_count >= $this->max_redemptions) {
            return __('This coupon has reached its usage limit.');
        }

        if ($this->per_user_limit > 0 && $this->redemptions()->where('user_id', $user->id)->count() >= $this->per_user_limit) {
            return __('You have already used this coupon.');
        }

        return null;
    }

    /**
     * Full validity for a specific plan + price. Null if the coupon is usable.
     */
    public function reasonInvalidFor(User $user, Plan $plan, float $amount): ?string
    {
        if ($reason = $this->globalReasonInvalid($user)) {
            return $reason;
        }

        if (! $this->appliesToPlan($plan)) {
            return __('This coupon is not valid for the selected plan.');
        }

        if ($this->min_amount !== null && $amount < (float) $this->min_amount) {
            return __('This coupon requires a minimum amount of :amount.', ['amount' => '₹'.number_format((float) $this->min_amount)]);
        }

        return null;
    }

    /**
     * The discount amount (in rupees) this coupon yields for the given price.
     */
    public function discountFor(float $amount): float
    {
        $discount = $this->discount_type === DiscountType::Percent
            ? $amount * ((float) $this->discount_value / 100)
            : (float) $this->discount_value;

        if ($this->max_discount_amount !== null) {
            $discount = min($discount, (float) $this->max_discount_amount);
        }

        return round(min($discount, $amount), 2);
    }
}
