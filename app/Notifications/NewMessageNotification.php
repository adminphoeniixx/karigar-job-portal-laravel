<?php

namespace App\Notifications;

use App\Models\ChatMessage;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A new chat message for the other side of a conversation.
 */
class NewMessageNotification extends Notification
{
    use Queueable;

    public function __construct(public ChatMessage $message) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create(
            $this->message->sender?->name ?? __('New message'),
            Str::limit($this->message->body, 120),
            [
                'type' => 'chat.message',
                'conversation_id' => $this->message->conversation_id,
                'url' => "/messages/{$this->message->conversation_id}",
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'chat.message',
            'conversation_id' => $this->message->conversation_id,
            'sender' => $this->message->sender?->name,
            'message' => Str::limit($this->message->body, 160),
            'url' => "/messages/{$this->message->conversation_id}",
        ];
    }
}
