<?php

namespace App\Models;

use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $pan_number
 * @property string|null $aadhaar_number
 * @property string|null $pan_doc_path
 * @property string|null $aadhaar_doc_path
 * @property KycStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $remarks
 */
class KycDocument extends Model
{
    protected $fillable = [
        'pan_number', 'aadhaar_number', 'aadhaar_hash',
        'pan_doc_path', 'aadhaar_doc_path', 'status',
        'reviewed_by', 'reviewed_at', 'remarks',
    ];

    /**
     * Raw identity numbers and document paths are never serialized to the frontend.
     *
     * @var list<string>
     */
    protected $hidden = [
        'pan_number', 'aadhaar_number', 'aadhaar_hash',
        'pan_doc_path', 'aadhaar_doc_path',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['masked_pan', 'masked_aadhaar'];

    protected function casts(): array
    {
        return [
            'pan_number' => 'encrypted',
            'aadhaar_number' => 'encrypted',
            'status' => KycStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function maskedPan(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->pan_number
            ? substr($this->pan_number, 0, 2).'XXXXX'.substr($this->pan_number, -1)
            : null);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function maskedAadhaar(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->aadhaar_number
            ? 'XXXX XXXX '.substr($this->aadhaar_number, -4)
            : null);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
