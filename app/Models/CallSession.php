<?php

namespace App\Models;

use App\Enums\CallStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One in-app voice call between an employer and a worker.
 *
 * The point of these calls is that neither side ever sees the other's number:
 * the audio runs over the provider's channel, and `channel` is a single-use
 * random room name that only the two participants get a token for. Phone
 * numbers stay behind the contact-unlock paywall (see ApplicantResource).
 *
 * @property int $id
 * @property int $caller_id
 * @property int $callee_id
 * @property int|null $job_application_id
 * @property string $channel
 * @property CallStatus $status
 * @property string|null $ended_reason
 * @property Carbon|null $answered_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 */
class CallSession extends Model
{
    protected $fillable = [
        'caller_id',
        'callee_id',
        'job_application_id',
        'channel',
        'status',
        'ended_reason',
        'answered_at',
        'ended_at',
        'duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'status' => CallStatus::class,
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    /**
     * Calls this user took part in, either side.
     *
     * @param  Builder<CallSession>  $query
     */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->where(function (Builder $q) use ($user) {
            $q->where('caller_id', $user->id)->orWhere('callee_id', $user->id);
        });
    }

    public function isParticipant(User $user): bool
    {
        return $this->caller_id === $user->id || $this->callee_id === $user->id;
    }

    /**
     * The other side of the call from `$user`'s point of view.
     */
    public function counterpartFor(User $user): ?User
    {
        return $this->caller_id === $user->id ? $this->callee : $this->caller;
    }

    /**
     * "Outgoing" for the caller, "incoming" for the person who was rung.
     */
    public function directionFor(User $user): string
    {
        return $this->caller_id === $user->id ? 'outgoing' : 'incoming';
    }
}
