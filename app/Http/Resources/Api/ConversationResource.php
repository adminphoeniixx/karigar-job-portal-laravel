<?php

namespace App\Http\Resources\Api;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One chat thread as the requesting side sees it — the other participant,
 * the last message and this user's unread count.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $other = $this->counterpartFor($user);
        $last = $this->messages->last() ?? $this->messages()->latest('id')->first();

        return [
            'id' => $this->id,
            'job' => $this->whenLoaded('job', fn () => $this->job ? [
                'id' => $this->job->id,
                'title' => $this->job->title,
            ] : null),
            'participant' => $other ? [
                'id' => $other->id,
                'name' => $other->name,
                'role' => $other->role->value,
                'avatar_url' => $this->isWorker($user)
                    ? $other->employerProfile?->logo_url
                    : $other->workerProfile?->avatar_url,
            ] : null,
            'last_message' => $last ? [
                'body' => $last->body,
                'sent_by_me' => $last->sender_id === $user->id,
                'created_ago' => $last->created_at?->diffForHumans(),
            ] : null,
            'unread' => $this->unreadCountFor($user),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
        ];
    }
}
