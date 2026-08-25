<?php

namespace App\Notifications;

use App\Models\ChatMessage;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\TemplatedMailChannel;
use App\Notifications\Messages\FcmMessage;
use App\Notifications\Messages\TemplatedMailMessage;
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
        return ['database', FcmChannel::class, TemplatedMailChannel::class];
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

    public function toTemplatedMail(object $notifiable): TemplatedMailMessage
    {
        return TemplatedMailMessage::create('chat_message', [
            'recipient_name' => $notifiable->name ?? '',
            'sender_name' => $this->message->sender?->name ?? __('Someone'),
            // A preview, not the message. The point of the email is to pull
            // them back into the chat, and quoting the lot invites a reply by
            // email to an address nobody reads.
            'message_preview' => Str::limit($this->message->body, 200),
            'action_url' => url("/messages/{$this->message->conversation_id}"),
        ]);
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
