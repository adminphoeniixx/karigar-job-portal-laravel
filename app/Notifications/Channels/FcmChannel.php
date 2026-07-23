<?php

namespace App\Notifications\Channels;

use App\Notifications\Messages\FcmMessage;
use App\Support\PushSender;
use Illuminate\Notifications\Notification;

/**
 * Custom notification channel that delivers a notification's toFcm() payload
 * as a push message to all of the notifiable's registered device tokens.
 */
class FcmChannel
{
    public function __construct(private PushSender $pushSender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        /** @var FcmMessage $message */
        $message = $notification->toFcm($notifiable);

        $tokens = $notifiable->routeNotificationFor('fcm', $notification);

        if (empty($tokens)) {
            return;
        }

        $this->pushSender->send((array) $tokens, $message->title, $message->body, $message->data);
    }
}
