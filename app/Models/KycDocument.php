<?php

namespace App\Models;

use App\Enums\KycStatus;
use App\Support\KycRequirements;
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
 * @property string|null $gst_doc_path
 * @property string|null $business_type
 * @property array<string, array{alt_type: string, alt_number: ?string, doc_path: ?string, reason: string}>|null $missing_documents
 * @property bool $has_missing_documents
 * @property KycStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $remarks
 */
class KycDocument extends Model
{
    protected $fillable = [
        'pan_number', 'aadhaar_number', 'aadhaar_hash',
        'pan_doc_path', 'aadhaar_doc_path', 'gst_doc_path', 'status',
        'reviewed_by', 'reviewed_at', 'remarks',
        'business_type', 'missing_documents', 'has_missing_documents',
    ];

    /**
     * Raw identity numbers and document paths are never serialized to the frontend.
     *
     * @var list<string>
     */
    protected $hidden = [
        'pan_number', 'aadhaar_number', 'aadhaar_hash',
        'pan_doc_path', 'aadhaar_doc_path', 'gst_doc_path', 'missing_documents',
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
            'missing_documents' => 'encrypted:array',
            'has_missing_documents' => 'boolean',
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

    /**
     * The proof file stored for one document (aadhaar, pan or gst).
     */
    public static function pathFor(self $kyc, string $doc): ?string
    {
        return match ($doc) {
            'aadhaar' => $kyc->aadhaar_doc_path,
            'pan' => $kyc->pan_doc_path,
            'gst' => $kyc->gst_doc_path,
            default => null,
        };
    }

    /**
     * Column holding one document's proof file.
     */
    public static function pathColumn(string $doc): string
    {
        return $doc.'_doc_path';
    }

    /**
     * One entry per document this submission covers: given (masked number,
     * whether a photo is on file) or missing (the alternate offered instead).
     * Admins get the full numbers to check them by hand; Aadhaar stays masked.
     *
     * @param  string|null  $gstin  an employer's GSTIN, kept on its profile
     * @return list<array<string, mixed>>
     */
    public function documentsSummary(?string $gstin = null, bool $forAdmin = false): array
    {
        $role = $this->user?->isEmployer() ? 'employer' : 'worker';
        $docs = KycRequirements::documentsFor($role, $this->business_type);
        $missing = $this->missing_documents ?? [];

        return collect($docs)->map(function (string $doc) use ($missing, $gstin, $forAdmin): array {
            $entry = [
                'type' => $doc,
                'label' => KycRequirements::DOCUMENTS[$doc]['label'],
                'missing' => isset($missing[$doc]),
            ];

            if (isset($missing[$doc])) {
                $alt = $missing[$doc];

                return $entry + [
                    'number' => null,
                    'has_file' => false,
                    'alternate' => [
                        'type' => $alt['alt_type'],
                        'label' => KycRequirements::ALTERNATES[$doc][$alt['alt_type']] ?? $alt['alt_type'],
                        'number' => $forAdmin ? $alt['alt_number'] : self::mask($alt['alt_number']),
                        'has_file' => ($alt['doc_path'] ?? null) !== null,
                        'reason' => $alt['reason'],
                    ],
                ];
            }

            $number = match ($doc) {
                'aadhaar' => $this->masked_aadhaar,
                'pan' => $forAdmin ? $this->pan_number : $this->masked_pan,
                'gst' => $gstin,
            };

            return $entry + [
                'number' => $number,
                'has_file' => self::pathFor($this, $doc) !== null,
                'alternate' => null,
            ];
        })->all();
    }

    private static function mask(?string $value): ?string
    {
        if ($value === null || strlen($value) <= 4) {
            return $value;
        }

        return str_repeat('X', strlen($value) - 4).substr($value, -4);
    }
}
