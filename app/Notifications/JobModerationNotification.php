<?php

namespace App\Notifications;

use App\Models\JobListing;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells an employer what happened to a job the safety check held: it is
 * waiting for review, it went live, or it was turned down (with the reason).
 */
class JobModerationNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $state  held | approved | rejected
     */
    public function __construct(public JobListing $job, public string $state) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create($this->title(), $this->line(), [
            'type' => 'job.moderation',
            'state' => $this->state,
            'job_listing_id' => $this->job->id,
            'url' => "/employer/jobs/{$this->job->id}/edit",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job.moderation',
            'state' => $this->state,
            'job_listing_id' => $this->job->id,
            'title' => $this->title(),
            'message' => $this->line(),
            'url' => "/employer/jobs/{$this->job->id}/edit",
        ];
    }

    private function title(): string
    {
        return match ($this->state) {
            'approved' => __('Your job is live'),
            'rejected' => __('Your job was not approved'),
            default => __('Your job is being reviewed'),
        };
    }

    private function line(): string
    {
        return match ($this->state) {
            'approved' => __('":title" passed review and is now live.', ['title' => $this->job->title]),
            'rejected' => __('":title" was not approved: :reason', ['title' => $this->job->title, 'reason' => $this->job->moderation_note ?: __('it breaks the posting rules')]),
            default => __('":title" needs a quick check by our team before it goes live. Usually within a working day.', ['title' => $this->job->title]),
        };
    }
}
