<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time contact-credit top-up bought through Razorpay.
 *
 * @property int $id
 * @property int $employer_id
 * @property string $pack
 * @property int $credits
 * @property string $amount
 * @property string|null $razorpay_order_id
 * @property string|null $razorpay_payment_id
 * @property string $status
 * @property Carbon|null $paid_at
 */
class CreditPurchase extends Model
{
    protected $fillable = [
        'employer_id', 'pack', 'credits', 'amount',
        'razorpay_order_id', 'razorpay_payment_id', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }
}
