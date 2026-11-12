<?php

namespace App\Models;

use App\Enums\ScreeningCallStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One AI call to a karigar or employer whose KYC is not done, explaining step
 * by step how to get verified. Placed by App\Services\Verification\VerificationCalls.
 *
 * Statuses are the screening call's (same line, same agent, same webhook).
 *
 * @property int $id
 * @property int $user_id
 * @property string $kyc_state not_started | rejected
 * @property string $source auto | admin | retry
 * @property int|null $requested_by
 * @property string $provider
 * @property string|null $provider_call_id
 * @property ScreeningCallStatus $status
 * @property string $language
 * @property int $attempt
 * @property Carbon|null $scheduled_at
 * @property string|null $outcome
 * @property string|null $summary
 * @property string|null $transcript
 * @property string|null $failure_reason
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 */
class VerificationCall extends Model
{
    public const STATE_NOT_STARTED = 'not_started';

    public const STATE_REJECTED = 'rejected';

    /** What the agent can say the call came to; see VerificationCallScript::extractionSchema(). */
    public const OUTCOMES = ['will_verify', 'needs_help', 'not_interested', 'callback_later', 'unclear'];

    protected $fillable = [
        'user_id',
        'kyc_state',
        'source',
        'requested_by',
        'provider',
        'provider_call_id',
        'status',
        'language',
        'attempt',
        'scheduled_at',
        'outcome',
        'summary',
        'transcript',
        'failure_reason',
        'started_at',
        'ended_at',
        'duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScreeningCallStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Booked, queued or on the line: a second call must not be added. */
    public function isOpen(): bool
    {
        return ! $this->status->isFinished();
    }
}
