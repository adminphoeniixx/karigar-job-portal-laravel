<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Decentro lookup: a PAN or GSTIN checked against the government
 * registry, or an Aadhaar read out of the user's DigiLocker. A DigiLocker
 * check starts `initiated` while the user is away signing in.
 *
 * @property int $id
 * @property int $user_id
 * @property string $document
 * @property string $method
 * @property string $reference
 * @property string|null $provider_txn_id
 * @property string|null $number_hash
 * @property string $status
 * @property string|null $name
 * @property array<string, mixed>|null $details
 * @property string|null $message
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
class KycCheck extends Model
{
    public const INITIATED = 'initiated';

    public const PASSED = 'passed';

    /** The registry has no such number, or says it is cancelled. */
    public const INVALID = 'invalid';

    /** Decentro or the registry could not answer; an admin decides instead. */
    public const ERROR = 'error';

    public const METHOD_REGISTRY = 'registry';

    public const METHOD_DIGILOCKER = 'digilocker';

    /** How long a DigiLocker session may stay open (DigiLocker's own limit is an hour). */
    public const DIGILOCKER_TTL_MINUTES = 60;

    protected $fillable = [
        'user_id', 'document', 'method', 'reference', 'provider_txn_id', 'number_hash',
        'status', 'name', 'details', 'message', 'completed_at',
    ];

    protected $hidden = ['name', 'details', 'number_hash'];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'details' => 'encrypted:array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function passed(): bool
    {
        return $this->status === self::PASSED;
    }

    public static function hashNumber(string $document, string $number): string
    {
        return hash('sha256', $document.'|'.strtoupper($number));
    }
}
