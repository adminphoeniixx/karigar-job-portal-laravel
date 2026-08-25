<?php

namespace App\Notifications\Channels;

use App\Notifications\Messages\TemplatedMailMessage;
use App\Support\TemplatedMailer;
use Illuminate\Notifications\Notification;

/**
 * Sends a notification's toTemplatedMail() payload as an admin-editable
 * transactional email. The mirror of FcmChannel, and used the same way: put
 * TemplatedMailChannel::class in via() and add a toTemplatedMail() method.
 *
 * Why a channel rather than a TemplatedMailer::send() call in the controller —
 * which is how the six older emails (application_submitted, job_posted, …) are
 * wired: those events fire from two places each, web and API, so every one of
 * them had to be remembered twice. Hanging the email off the notification means
 * the dispatch sites do not change at all, and an event that already pushes
 * cannot silently forget to mail.
 */
class TemplatedMailChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toTemplatedMail')) {
            return;
        }

        /** @var TemplatedMailMessage $message */
        $message = $notification->toTemplatedMail($notifiable);

        // routeNotificationFor('mail') is Notifiable's own accessor for the
        // address — it returns $notifiable->email unless a model overrides it.
        $email = $notifiable->routeNotificationFor('mail', $notification);

        // A notifiable may route mail as a plain address, as a list, or as
        // ['address' => 'Name']. Only the first address matters here.
        if (is_array($email)) {
            $first = array_key_first($email);
            $email = is_string($first) ? $first : ($email[$first] ?? null);
        }

        // A missing address, a @phone.karigar placeholder, a template that is
        // switched off — TemplatedMailer decides all of that, and returns
        // quietly. Nothing here needs to guard for it.
        TemplatedMailer::send($message->key, $email, $message->data);
    }
}
