<?php

namespace App\Notifications;

use App\Models\JobListing;
use App\Notifications\Channels\TemplatedMailChannel;
use App\Notifications\Messages\TemplatedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the admins a job was held by the safety check and is waiting on one
 * of them: in the panel's bell and by email. Admins have no app, so no push.
 */
class JobHeldForReviewNotification extends Notification
{
    use Queueable;

    public function __construct(public JobListing $job) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', TemplatedMailChannel::class];
    }

    public function toTemplatedMail(object $notifiable): TemplatedMailMessage
    {
        return TemplatedMailMessage::create('job_review_needed', [
            'employer_name' => $this->job->employer?->name ?? '—',
            'job_title' => $this->job->title,
            'reasons' => $this->reasons(),
            'action_url' => url('/admin/jobs?status=review'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job.review_needed',
            'job_listing_id' => $this->job->id,
            'title' => __('A job needs review'),
            'message' => __('":title" by :employer was held: :reasons', [
                'title' => $this->job->title,
                'employer' => $this->job->employer?->name ?? '—',
                'reasons' => $this->reasons(),
            ]),
            'url' => '/admin/jobs?status=review',
        ];
    }

    private function reasons(): string
    {
        return implode('; ', $this->job->moderation_reasons ?? []) ?: '—';
    }
}
