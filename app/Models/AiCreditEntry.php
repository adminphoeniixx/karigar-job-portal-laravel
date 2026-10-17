<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an employer's AI credit ledger. Rows are only ever added:
 * `credits` is signed, and `balance_after` is the balance once it applied.
 *
 * @property int $id
 * @property int $employer_id
 * @property string $type
 * @property int $credits
 * @property int $balance_after
 * @property string $description
 * @property int|null $ai_credit_purchase_id
 * @property int|null $screening_call_id
 * @property int|null $seconds
 * @property int|null $created_by
 */
class AiCreditEntry extends Model
{
    public const TYPE_RECHARGE = 'recharge';

    public const TYPE_BONUS = 'bonus';

    public const TYPE_WELCOME = 'welcome';

    public const TYPE_CALL = 'call';

    public const TYPE_ADMIN_CREDIT = 'admin_credit';

    public const TYPE_ADMIN_DEBIT = 'admin_debit';

    public const TYPE_REFUND = 'refund';

    public const TYPES = [
        self::TYPE_RECHARGE, self::TYPE_BONUS, self::TYPE_WELCOME, self::TYPE_CALL,
        self::TYPE_ADMIN_CREDIT, self::TYPE_ADMIN_DEBIT, self::TYPE_REFUND,
    ];

    protected $fillable = [
        'employer_id', 'type', 'credits', 'balance_after', 'description',
        'ai_credit_purchase_id', 'screening_call_id', 'seconds', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'balance_after' => 'integer',
            'seconds' => 'integer',
        ];
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_RECHARGE => __('Recharge'),
            self::TYPE_BONUS => __('Bonus'),
            self::TYPE_WELCOME => __('Welcome credits'),
            self::TYPE_CALL => __('AI call'),
            self::TYPE_ADMIN_CREDIT => __('Added by Super Karigar'),
            self::TYPE_ADMIN_DEBIT => __('Deducted by Super Karigar'),
            self::TYPE_REFUND => __('Refund'),
            default => $type,
        };
    }

    /**
     * The row as both apps show it.
     *
     * @return array<string, mixed>
     */
    public function toRow(bool $api = false): array
    {
        $tz = config('app.display_timezone');
        $invoice = $this->type === self::TYPE_RECHARGE ? $this->purchase?->invoice : null;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => self::typeLabel($this->type),
            // credit (adds) | debit (takes)
            'direction' => $this->credits >= 0 ? 'credit' : 'debit',
            'credits' => $this->credits,
            'balance_after' => $this->balance_after,
            'description' => $this->description,
            'seconds' => $this->seconds,
            'screening_call_id' => $this->screening_call_id,
            'amount_paid' => $this->type === self::TYPE_RECHARGE && $this->purchase ? (float) $this->purchase->total_amount : null,
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'number' => $invoice->number,
                ...($api ? [
                    'url' => route('api.employer.invoices.show', $invoice),
                    'pdf_url' => route('api.employer.invoices.pdf', $invoice),
                ] : []),
                'web_url' => route('invoices.show', $invoice),
                'web_pdf_url' => route('invoices.pdf', $invoice),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_label' => $this->created_at?->timezone($tz)->format('d M Y, g:i A'),
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    /**
     * @return BelongsTo<AiCreditPurchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(AiCreditPurchase::class, 'ai_credit_purchase_id');
    }

    /**
     * The admin who added or took these credits by hand.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<ScreeningCall, $this>
     */
    public function call(): BelongsTo
    {
        return $this->belongsTo(ScreeningCall::class, 'screening_call_id');
    }
}
