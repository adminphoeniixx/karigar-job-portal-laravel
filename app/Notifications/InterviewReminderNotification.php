<?php

namespace App\Notifications;

use App\Models\JobApplication;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\TemplatedMailChannel;
use App\Notifications\Messages\FcmMessage;
use App\Notifications\Messages\TemplatedMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "Your interview is today": the morning reminder, to the karigar and to the
 * employer, each worded for their side.
 */
class InterviewReminderNotification extends Notification
{
    use Queueable;

    public function __construct(public JobApplication $application, public string $audience = 'worker') {}

    public function via(object $notifiable): array
    {
        // The karigar gets the email too (the address, the time, in writing);
        // the employer has the applicants screen for that.
        return $this->audience === 'worker'
            ? ['database', FcmChannel::class, TemplatedMailChannel::class]
            : ['database', FcmChannel::class];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create(__('Interview today'), $this->line(), [
            'type' => 'application.interview_reminder',
            'application_id' => $this->application->id,
            'url' => $this->url(),
        ]);
    }

    public function toTemplatedMail(object $notifiable): TemplatedMailMessage
    {
        $job = $this->application->job;

        return TemplatedMailMessage::create('interview_reminder', [
            'worker_name' => $this->application->worker->name,
            'employer_name' => $job->employer?->employerProfile?->company_name ?: ($job->employer?->name ?? ''),
            'job_title' => $job->title,
            'job_location' => trim(implode(', ', array_filter([$job->address, $job->city, $job->state]))),
            'interview_at' => $this->when('l, d M Y, g:i A'),
            'interview_mode' => $this->modeLabel(),
            'contact_name' => trim((string) $job->contact_name) ?: '—',
            'action_url' => url('/worker/applications'),
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'application.interview_reminder',
            'application_id' => $this->application->id,
            'job_listing_id' => $this->application->job_listing_id,
            'job_title' => $this->application->job->title,
            'interview_at' => $this->application->interview_at?->toIso8601String(),
            'interview_mode' => $this->application->interview_mode,
            'message' => $this->line(),
            'url' => $this->url(),
        ];
    }

    private function line(): string
    {
        $job = $this->application->job;
        $when = $this->when('g:i A');

        if ($this->audience === 'employer') {
            return __('Interview with :worker for ":job" today at :when.', [
                'worker' => $this->application->worker->name,
                'job' => $job->title,
                'when' => $when,
            ]);
        }

        return match ($this->application->interview_mode) {
            'phone' => __('Interview for ":job" today at :when — the employer will call you. Keep your phone close.', ['job' => $job->title, 'when' => $when]),
            'video' => __('Interview for ":job" today at :when, on a video call.', ['job' => $job->title, 'when' => $when]),
            default => __('Interview for ":job" today at :when:place. Please reach a little early.', [
                'job' => $job->title,
                'when' => $when,
                'place' => ($place = trim(implode(', ', array_filter([$job->address, $job->city])))) !== '' ? ' at '.$place : '',
            ]),
        };
    }

    private function when(string $format): string
    {
        // Indian wall-clock as typed, like every other interview time shown.
        return $this->application->interview_at?->format($format) ?? '—';
    }

    private function modeLabel(): string
    {
        return match ($this->application->interview_mode) {
            'phone' => __('Over the phone — the employer will call you'),
            'video' => __('Video call'),
            default => __('In person, at the worksite'),
        };
    }

    private function url(): string
    {
        return $this->audience === 'employer'
            ? "/employer/jobs/{$this->application->job_listing_id}/applicants"
            : '/worker/applications';
    }
}
