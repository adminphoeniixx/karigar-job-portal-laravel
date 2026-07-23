<?php

namespace App\Notifications;

use App\Models\JobApplication;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ShortlistedNotification extends Notification
{
    use Queueable;

    public function __construct(public JobApplication $application) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    /**
     * Push payload delivered to the worker's device.
     */
    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create(
            'You were shortlisted',
            "You were shortlisted for \"{$this->application->job->title}\".",
            [
                'type' => 'application.shortlisted',
                'application_id' => $this->application->id,
                'url' => '/worker/applications',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'application.shortlisted',
            'application_id' => $this->application->id,
            'job_listing_id' => $this->application->job_listing_id,
            'job_title' => $this->application->job->title,
            'message' => "You were shortlisted for \"{$this->application->job->title}\".",
            'url' => '/worker/applications',
        ];
    }
}
