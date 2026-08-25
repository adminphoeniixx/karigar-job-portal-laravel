<?php

namespace App\Notifications;

use App\Models\JobListing;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\TemplatedMailChannel;
use App\Notifications\Messages\FcmMessage;
use App\Notifications\Messages\TemplatedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewJobNotification extends Notification
{
    use Queueable;

    public function __construct(public JobListing $job) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class, TemplatedMailChannel::class];
    }

    /**
     * Push payload delivered to the worker's device.
     */
    public function toFcm(object $notifiable): FcmMessage
    {
        $location = collect([$this->job->city, $this->job->state])->filter()->join(', ');

        return FcmMessage::create(
            'New job posted',
            "\"{$this->job->title}\"".($location ? " in {$location}." : '.'),
            [
                'type' => 'job.posted',
                'job_listing_id' => $this->job->id,
                'url' => "/worker/jobs/{$this->job->id}",
            ],
        );
    }

    public function toTemplatedMail(object $notifiable): TemplatedMailMessage
    {
        return TemplatedMailMessage::create('job_posted_match', [
            'worker_name' => $notifiable->name ?? '',
            'employer_name' => $this->job->employer?->name ?? '',
            'job_title' => $this->job->title,
            'job_location' => collect([$this->job->city, $this->job->state])->filter()->join(', '),
            'job_category' => $this->job->category ?? '',
            'action_url' => url("/worker/jobs/{$this->job->id}"),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $location = collect([$this->job->city, $this->job->state])->filter()->join(', ');

        return [
            'type' => 'job.posted',
            'job_listing_id' => $this->job->id,
            'job_title' => $this->job->title,
            'category' => $this->job->category,
            'message' => "New job posted: \"{$this->job->title}\"".($location ? " in {$location}." : '.'),
            'url' => "/worker/jobs/{$this->job->id}",
        ];
    }
}
