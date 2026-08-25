<?php

namespace App\Notifications;

use App\Models\JobApplication;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\TemplatedMailChannel;
use App\Notifications\Messages\FcmMessage;
use App\Notifications\Messages\TemplatedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InterviewScheduledNotification extends Notification
{
    use Queueable;

    public function __construct(public JobApplication $application) {}

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
            __('Interview scheduled'),
            $this->line(),
            [
                'type' => 'application.interview',
                'application_id' => $this->application->id,
                'url' => '/worker/applications',
            ],
        );
    }

    public function toTemplatedMail(object $notifiable): TemplatedMailMessage
    {
        $job = $this->application->job;

        return TemplatedMailMessage::create('interview_scheduled', [
            'worker_name' => $this->application->worker->name,
            'employer_name' => $job->employer?->name ?? '',
            'job_title' => $job->title,
            'job_location' => trim(implode(', ', array_filter([$job->city, $job->state]))),
            // Spelled out in full, unlike the push, which only has room for
            // "12 Sep, 4:00 PM". An interview time read off an email hours
            // later needs the weekday and the year to be unambiguous.
            'interview_at' => $this->application->interview_at?->format('l, d M Y, g:i A') ?? '—',
            'interview_mode' => $this->modeLabel(),
            'action_url' => url('/worker/applications'),
        ]);
    }

    /**
     * The three modes the rest of the app uses. Shown to the worker as the
     * thing they have to *do*, not as the enum value.
     */
    private function modeLabel(): string
    {
        return match ($this->application->interview_mode) {
            'site' => __('In person, at the worksite'),
            'phone' => __('Over the phone'),
            'video' => __('Video call'),
            default => __('The employer will confirm how'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'application.interview',
            'application_id' => $this->application->id,
            'job_listing_id' => $this->application->job_listing_id,
            'job_title' => $this->application->job->title,
            'interview_at' => $this->application->interview_at?->toIso8601String(),
            'interview_mode' => $this->application->interview_mode,
            'message' => $this->line(),
            'url' => '/worker/applications',
        ];
    }

    private function line(): string
    {
        $when = $this->application->interview_at?->format('d M, g:i A') ?? '—';

        return __('Interview for ":job" on :when.', [
            'job' => $this->application->job->title,
            'when' => $when,
        ]);
    }
}
