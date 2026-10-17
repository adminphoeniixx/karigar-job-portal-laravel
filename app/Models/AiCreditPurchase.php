<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One recharge: a Razorpay order for a pack, with the GST worked out when it
 * was opened. Becomes `paid` once, when the payment is confirmed, and that is
 * when the credits land and the invoice is issued.
 *
 * @property int $id
 * @property int $employer_id
 * @property int|null $ai_credit_pack_id
 * @property string $pack_name
 * @property int $credits
 * @property int $bonus_credits
 * @property string $status
 * @property string $razorpay_order_id
 * @property string|null $razorpay_payment_id
 * @property Carbon|null $paid_at
 */
class AiCreditPurchase extends Model
{
    public const STATUS_CREATED = 'created';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'employer_id', 'ai_credit_pack_id', 'pack_name', 'credits', 'bonus_credits', 'status',
        'razorpay_order_id', 'razorpay_payment_id',
        'subtotal_amount', 'gst_percent', 'gst_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'total_amount',
        'place_of_supply', 'seller_gstin', 'sac_code', 'paid_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'bonus_credits' => 'integer',
            'subtotal_amount' => 'decimal:2',
            'gst_percent' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function totalCredits(): int
    {
        return $this->credits + $this->bonus_credits;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    /**
     * @return BelongsTo<AiCreditPack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(AiCreditPack::class, 'ai_credit_pack_id');
    }

    /**
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
