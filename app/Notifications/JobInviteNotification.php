<?php

namespace App\Notifications;

use App\Models\JobListing;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An employer invited this worker to apply to one of their jobs.
 */
class JobInviteNotification extends Notification
{
    use Queueable;

    public function __construct(public JobListing $job, public ?string $note = null) {}

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
            __('You have been invited to apply'),
            $this->line(),
            [
                'type' => 'job.invite',
                'job_listing_id' => $this->job->id,
                'url' => "/jobs/{$this->job->id}",
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job.invite',
            'job_listing_id' => $this->job->id,
            'job_title' => $this->job->title,
            'note' => $this->note,
            'message' => $this->line(),
            'url' => "/jobs/{$this->job->id}",
        ];
    }

    private function line(): string
    {
        return __(':employer invited you to apply for ":job".', [
            'employer' => $this->job->employer?->name ?? __('An employer'),
            'job' => $this->job->title,
        ]);
    }
}
