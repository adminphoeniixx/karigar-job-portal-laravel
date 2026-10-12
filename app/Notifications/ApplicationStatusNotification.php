<?php

namespace App\Notifications;

use App\Enums\ApplicationStatus;
use App\Models\JobApplication;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ApplicationStatusNotification extends Notification
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

    /**
     * Push payload delivered to the worker's device. A hire carries the
     * offer: who hired them, the pay and the start date.
     */
    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create(
            $this->title(),
            $this->message(),
            [
                'type' => 'application.'.$this->application->status->value,
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
            'type' => 'application.'.$this->application->status->value,
            'application_id' => $this->application->id,
            'job_listing_id' => $this->application->job_listing_id,
            'job_title' => $this->application->job->title,
            'status' => $this->application->status->value,
            'title' => $this->title(),
            'message' => $this->message(),
            // The hire offer (null unless hired), so the notification screen
            // can show it without another call.
            'offer' => $this->application->offerForWorker(),
            'url' => '/worker/applications',
        ];
    }

    private function hired(): bool
    {
        return $this->application->status === ApplicationStatus::Accepted;
    }

    private function title(): string
    {
        return $this->hired()
            ? __('You\'re hired! 🎉')
            : __('Application :status', ['status' => $this->application->status->label()]);
    }

    /**
     * "Sharma Interiors hired you for "Electrician". ₹23,400 / month ·
     * Joining 12 Oct 2026", leaving out whatever the employer did not fill in.
     */
    private function message(): string
    {
        $job = $this->application->job->title;

        if (! $this->hired()) {
            return __('Your application for ":job" was :status.', ['job' => $job, 'status' => $this->application->status->label()]);
        }

        $offer = $this->application->offerForWorker();
        $details = array_filter([
            $offer['wage_label'],
            $offer['start_date_label'] ? __('Joining :date', ['date' => $offer['start_date_label']]) : null,
        ]);

        return __(':employer hired you for ":job".', ['employer' => $offer['employer_name'], 'job' => $job])
            .($details ? ' '.implode(' · ', $details) : '');
    }
}
