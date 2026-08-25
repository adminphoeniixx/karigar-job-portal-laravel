<?php

namespace App\Notifications;

use App\Models\JobListing;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\TemplatedMailChannel;
use App\Notifications\Messages\FcmMessage;
use App\Notifications\Messages\TemplatedMailMessage;
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
        return ['database', FcmChannel::class, TemplatedMailChannel::class];
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

    public function toTemplatedMail(object $notifiable): TemplatedMailMessage
    {
        return TemplatedMailMessage::create('job_invite', [
            'worker_name' => $notifiable->name ?? '',
            'employer_name' => $this->job->employer?->name ?? __('An employer'),
            'job_title' => $this->job->title,
            'job_location' => trim(implode(', ', array_filter([$this->job->city, $this->job->state]))),
            // The employer's own words, when they wrote any. Templates are
            // plain {{ placeholder }} substitution with no conditionals, so an
            // empty value would render an empty quote block — hence a sentence
            // rather than ''.
            'note' => $this->note ?: __('They did not leave a message, but they picked you out themselves.'),
            'action_url' => url("/jobs/{$this->job->id}"),
        ]);
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
