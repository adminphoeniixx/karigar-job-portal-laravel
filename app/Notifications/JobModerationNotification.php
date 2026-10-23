<?php

namespace App\Notifications;

use App\Models\JobListing;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\TemplatedMailChannel;
use App\Notifications\Messages\FcmMessage;
use App\Notifications\Messages\TemplatedMailMessage;
use App\Support\JobAnnouncer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells an employer what the job safety check decided: it passed and the job
 * is live, an edit passed and is live again, it is held for review, or an
 * admin approved or turned it down (with the reason).
 *
 * Held and turned down also go by email. A pass does not: the "job_posted"
 * email already says the job is live ({@see JobAnnouncer}).
 */
class JobModerationNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $state  passed | updated | held | approved | rejected
     */
    public function __construct(public JobListing $job, public string $state) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return in_array($this->state, ['held', 'rejected'], true)
            ? ['database', FcmChannel::class, TemplatedMailChannel::class]
            : ['database', FcmChannel::class];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create($this->title(), $this->line(), [
            'type' => 'job.moderation',
            'state' => $this->state,
            'job_listing_id' => $this->job->id,
            'url' => $this->url(),
        ]);
    }

    public function toTemplatedMail(object $notifiable): TemplatedMailMessage
    {
        return TemplatedMailMessage::create($this->state === 'rejected' ? 'job_not_approved' : 'job_under_review', [
            'employer_name' => $notifiable->name ?? '',
            'job_title' => $this->job->title,
            'reason' => $this->job->moderation_note ?: __('it breaks the posting rules'),
            'action_url' => url($this->url()),
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
            'url' => $this->url(),
        ];
    }

    /**
     * A live job opens on its applicants, one that is not on its edit form.
     */
    private function url(): string
    {
        return in_array($this->state, ['held', 'rejected'], true)
            ? "/employer/jobs/{$this->job->id}/edit"
            : "/employer/jobs/{$this->job->id}/applicants";
    }

    private function title(): string
    {
        return match ($this->state) {
            'passed', 'approved' => __('Your job is live'),
            'updated' => __('Your changes are live'),
            'rejected' => __('Your job was not approved'),
            default => __('Your job is being reviewed'),
        };
    }

    private function line(): string
    {
        return match ($this->state) {
            'passed' => __('":title" passed the safety check and is now live. Karigars nearby are being told about it.', ['title' => $this->job->title]),
            'updated' => __('Your changes to ":title" passed the safety check and are live.', ['title' => $this->job->title]),
            'approved' => __('":title" passed review and is now live.', ['title' => $this->job->title]),
            'rejected' => __('":title" was not approved: :reason', ['title' => $this->job->title, 'reason' => $this->job->moderation_note ?: __('it breaks the posting rules')]),
            default => __('":title" needs a quick check by our team before it goes live. Usually within a working day.', ['title' => $this->job->title]),
        };
    }
}
