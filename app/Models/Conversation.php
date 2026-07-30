<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A direct chat thread between one employer account and one worker, optionally
 * tied to the job the conversation started from.
 *
 * @property int $id
 * @property int $employer_id
 * @property int $worker_id
 * @property int|null $job_listing_id
 * @property Carbon|null $last_message_at
 */
class Conversation extends Model
{
    protected $fillable = ['employer_id', 'worker_id', 'job_listing_id', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
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
     * @return BelongsTo<JobListing, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'job_listing_id');
    }

    /**
     * Threads this user takes part in (employer accounts see their own threads;
     * team members see the owner account's threads).
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->where(function (Builder $q) use ($user) {
            $q->where('worker_id', $user->id)
                ->orWhere('employer_id', $user->employerAccount()->id);
        });
    }

    /**
     * The other side of the thread from `$user`'s point of view.
     */
    public function counterpartFor(User $user): ?User
    {
        return $this->isWorker($user) ? $this->employer : $this->worker;
    }

    public function isWorker(User $user): bool
    {
        return $this->worker_id === $user->id;
    }

    public function isParticipant(User $user): bool
    {
        return $this->worker_id === $user->id
            || $this->employer_id === $user->employerAccount()->id;
    }

    /**
     * Unread messages for this user — i.e. unread messages the other side sent.
     * The employer side may be several people (team members), so "mine" is
     * decided by whether the sender is the worker.
     */
    public function unreadCountFor(User $user): int
    {
        return $this->messages()
            ->whereNull('read_at')
            ->when(
                $this->isWorker($user),
                fn ($q) => $q->where('sender_id', '!=', $this->worker_id),
                fn ($q) => $q->where('sender_id', $this->worker_id),
            )
            ->count();
    }
}
