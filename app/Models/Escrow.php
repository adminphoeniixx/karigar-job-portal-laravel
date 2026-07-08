<?php

namespace App\Models;

use App\Enums\EscrowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $job_application_id
 * @property int $employer_id
 * @property int $worker_id
 * @property string $amount
 * @property string $commission
 * @property string $payout_amount
 * @property EscrowStatus $status
 */
class Escrow extends Model
{
    protected $fillable = [
        'job_application_id', 'employer_id', 'worker_id',
        'amount', 'commission', 'payout_amount', 'currency', 'status',
        'razorpay_order_id', 'razorpay_payment_id', 'razorpay_payout_id',
        'funded_at', 'release_requested_at', 'released_at', 'refunded_at', 'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'commission' => 'decimal:2',
            'payout_amount' => 'decimal:2',
            'status' => EscrowStatus::class,
            'funded_at' => 'datetime',
            'release_requested_at' => 'datetime',
            'released_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    /**
     * @return HasMany<EscrowLedger, $this>
     */
    public function ledger(): HasMany
    {
        return $this->hasMany(EscrowLedger::class);
    }
}
