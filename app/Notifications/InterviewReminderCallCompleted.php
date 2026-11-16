<?php

namespace App\Notifications;

use App\Enums\ScreeningCallStatus;
use App\Models\InterviewReminderCall;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * What the karigar said on the reminder call, to the employer: coming, not
 * coming, or asking for another time.
 */
class InterviewReminderCallCompleted extends Notification
{
    use Queueable;

    public function __construct(public InterviewReminderCall $call) {}

    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create($this->title(), $this->line(), [
            'type' => 'interview.reminder_call',
            'interview_reminder_call_id' => $this->call->id,
            'application_id' => $this->call->job_application_id,
            'url' => $this->url(),
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'interview.reminder_call',
            'interview_reminder_call_id' => $this->call->id,
            'application_id' => $this->call->job_application_id,
            'status' => $this->call->status->value,
            'outcome' => $this->call->outcome,
            'outcome_label' => $this->call->outcomeLabel(),
            'proposed_interview_at' => $this->call->proposed_interview_at?->toIso8601String(),
            'summary' => $this->call->summary,
            'message' => $this->line(),
            'url' => $this->url(),
        ];
    }

    private function title(): string
    {
        if ($this->call->status !== ScreeningCallStatus::Completed) {
            return __('Applicant could not be reached');
        }

        return match ($this->call->outcome) {
            'will_come' => __('Applicant confirmed for today'),
            'reschedule' => __('Applicant asked to move the interview'),
            'not_coming' => __('Applicant is not coming'),
            default => __('Interview reminder call done'),
        };
    }

    private function line(): string
    {
        $worker = $this->call->worker?->name ?? __('The applicant');
        $job = $this->call->application?->job?->title ?? '';

        if ($this->call->status !== ScreeningCallStatus::Completed) {
            return __('The AI could not reach :worker to remind them of the ":job" interview today (:why). You may want to call them yourself.', [
                'worker' => $worker,
                'job' => $job,
                'why' => strtolower($this->call->status->label()),
            ]);
        }

        return match ($this->call->outcome) {
            'will_come' => __(':worker confirmed they are coming to the ":job" interview today.', ['worker' => $worker, 'job' => $job]),
            'reschedule' => $this->call->proposed_interview_at !== null
                ? __(':worker cannot make today and asked for :when instead. Reschedule from the applicants screen.', [
                    'worker' => $worker,
                    // Stored as Indian wall-clock, like the screening call's slot.
                    'when' => $this->call->proposed_interview_at->format('D d M, g:i A'),
                ])
                : __(':worker cannot make today and asked for another time.', ['worker' => $worker]),
            'not_coming' => __(':worker said they will not come to the ":job" interview.', ['worker' => $worker, 'job' => $job]),
            default => __('The reminder call to :worker ended without a clear answer.', ['worker' => $worker]),
        };
    }

    private function url(): string
    {
        $jobId = $this->call->application?->job_listing_id;

        return $jobId !== null ? "/employer/jobs/{$jobId}/applicants" : '/employer/jobs';
    }
}
