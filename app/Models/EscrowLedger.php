<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $escrow_id
 * @property string $type
 * @property string $amount
 */
class EscrowLedger extends Model
{
    protected $table = 'escrow_ledger';

    public $timestamps = false;

    protected $fillable = ['escrow_id', 'type', 'amount', 'reference', 'meta', 'created_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Escrow, $this>
     */
    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }
}
