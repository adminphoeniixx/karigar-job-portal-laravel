<?php

namespace App\Notifications;

use App\Models\JobListing;
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
        return ['database'];
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
