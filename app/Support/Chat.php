<?php

namespace App\Support;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\User;
use App\Notifications\NewMessageNotification;

/**
 * The chat rules shared by the mobile API (Api\ChatController) and the web
 * Messages screen (MessageController), kept in one place so the two can't
 * drift apart. Threads belong to the employer *account*, so team members
 * share a single inbox.
 */
class Chat
{
    /**
     * The [employer_id, worker_id] pair for `$actor` talking to `$counterpartId`,
     * or null when the actor is not allowed to open that thread.
     *
     * @return array{0: int, 1: int}|null
     */
    public function participants(User $actor, int $counterpartId): ?array
    {
        // The caller's own side always comes from the session/token, never
        // from the request body.
        [$employerId, $workerId] = $actor->isWorker()
            ? [$counterpartId, $actor->id]
            : [$actor->employerAccount()->id, $counterpartId];

        return $this->mayChat($employerId, $workerId) ? [$employerId, $workerId] : null;
    }

    /**
     * Find or start the thread for this pair, pinned to a job when the
     * employer actually owns it.
     */
    public function open(int $employerId, int $workerId, ?int $jobId = null): Conversation
    {
        return Conversation::firstOrCreate([
            'employer_id' => $employerId,
            'worker_id' => $workerId,
            'job_listing_id' => $this->resolveJobId($jobId, $employerId),
        ]);
    }

    /**
     * Store a message, bump the thread and notify the other side.
     */
    public function push(Conversation $conversation, User $sender, string $body): ChatMessage
    {
        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => $body,
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        $recipient = $conversation->isWorker($sender)
            ? $conversation->employer
            : $conversation->worker;

        $message->setRelation('sender', $sender);
        $recipient?->notify(new NewMessageNotification($message));

        return $message;
    }

    /**
     * Mark every incoming message in the thread as read.
     */
    public function markRead(Conversation $conversation, User $user): void
    {
        $conversation->messages()
            ->whereNull('read_at')
            ->when(
                $conversation->isWorker($user),
                fn ($q) => $q->where('sender_id', '!=', $conversation->worker_id),
                fn ($q) => $q->where('sender_id', $conversation->worker_id),
            )
            ->update(['read_at' => now()]);
    }

    /**
     * Unread messages across every thread this user takes part in.
     */
    public function unreadTotal(User $user): int
    {
        return ChatMessage::whereNull('read_at')
            ->whereHas('conversation', function ($q) use ($user) {
                $q->forUser($user);

                // "Not mine": the worker's own messages for the employer side,
                // and everything but the worker's for the worker side.
                $user->isWorker()
                    ? $q->whereColumn('conversations.worker_id', '!=', 'chat_messages.sender_id')
                    : $q->whereColumn('conversations.worker_id', '=', 'chat_messages.sender_id');
            })
            ->count();
    }

    /**
     * Employers may only message workers who applied to one of their jobs;
     * workers may reply to those.
     */
    public function mayChat(int $employerId, int $workerId): bool
    {
        return JobApplication::where('worker_id', $workerId)
            ->whereHas('job', fn ($q) => $q->where('employer_id', $employerId))
            ->exists();
    }

    /**
     * Only accept a job id the employer actually owns.
     */
    public function resolveJobId(?int $jobId, int $employerId): ?int
    {
        if ($jobId === null) {
            return null;
        }

        return JobListing::where('id', $jobId)->where('employer_id', $employerId)->exists()
            ? $jobId
            : null;
    }
}
