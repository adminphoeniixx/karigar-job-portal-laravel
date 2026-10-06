<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\JobInviteNotification;
use App\Notifications\NewJobNotification;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * A karigar who switched "Available for work" off is left alone: no push and
 * no email from any notification. Updates on their own applications are still
 * kept in the in-app list for when they come back; job alerts and invites are
 * not even kept, since they are about finding new work.
 *
 * Returning false from a NotificationSending listener drops that one channel.
 */
class HoldAlertsForUnavailableWorkers
{
    /** Notifications about new work, dropped on every channel. */
    private const NEW_WORK = [NewJobNotification::class, JobInviteNotification::class];

    public function handle(NotificationSending $event): ?bool
    {
        if (! $event->notifiable instanceof User || ! $event->notifiable->isUnavailableWorker()) {
            return null;
        }

        if (in_array($event->notification::class, self::NEW_WORK, true)) {
            return false;
        }

        return $event->channel === 'database' ? null : false;
    }
}
