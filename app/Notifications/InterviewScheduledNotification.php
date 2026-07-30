<?php

namespace App\Notifications;

use App\Models\JobApplication;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
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
        return ['database', FcmChannel::class];
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
