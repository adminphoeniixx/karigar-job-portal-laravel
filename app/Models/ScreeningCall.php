<?php

namespace App\Models;

use App\Enums\ScreeningCallStatus;
use App\Enums\ScreeningOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One automated screening call placed to a worker after the AI shortlisted
 * their application.
 *
 * The platform dials the worker's real phone from its own virtual number and
 * an AI agent asks whether they are still interested and when they could come
 * in. The employer never sees the number — they see the outcome and the slot
 * the worker proposed, which they then confirm.
 *
 * @property int $id
 * @property int $job_application_id
 * @property int $worker_id
 * @property string $provider
 * @property string|null $provider_call_id
 * @property ScreeningCallStatus $status
 * @property string $language
 * @property int $attempt
 * @property ScreeningOutcome|null $outcome
 * @property Carbon|null $proposed_interview_at
 * @property string|null $proposed_mode
 * @property bool $employer_confirmed
 * @property string|null $summary
 * @property string|null $transcript
 * @property string|null $failure_reason
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 */
class ScreeningCall extends Model
{
    protected $fillable = [
        'job_application_id',
        'worker_id',
        'provider',
        'provider_call_id',
        'status',
        'language',
        'attempt',
        'outcome',
        'proposed_interview_at',
        'proposed_mode',
        'employer_confirmed',
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
            'outcome' => ScreeningOutcome::class,
            'proposed_interview_at' => 'datetime',
            'employer_confirmed' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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
    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    /**
     * A slot the worker offered that the employer has not acted on yet.
     */
    public function awaitingConfirmation(): bool
    {
        return $this->proposed_interview_at !== null && ! $this->employer_confirmed;
    }
}
