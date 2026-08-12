<?php

namespace App\Notifications;

use App\Models\ScreeningCall;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the employer how the automated screening call went. When the worker
 * offered an interview time this is the employer's cue to confirm it — the
 * agent only ever collects a preference.
 *
 * Carries no phone number: the platform placed the call, and the worker's
 * contact stays behind the unlock paywall.
 */
class ScreeningCallCompleted extends Notification
{
    use Queueable;

    public function __construct(public ScreeningCall $call) {}

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
            $this->call->awaitingConfirmation() ? __('Interview time proposed') : __('Screening call done'),
            $this->line(),
            [
                'type' => 'screening.completed',
                'screening_call_id' => $this->call->id,
                'application_id' => $this->call->job_application_id,
                'url' => '/employer/applicants',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'screening.completed',
            'screening_call_id' => $this->call->id,
            'application_id' => $this->call->job_application_id,
            'outcome' => $this->call->outcome?->value,
            'outcome_label' => $this->call->outcome?->label(),
            'proposed_interview_at' => $this->call->proposed_interview_at?->toIso8601String(),
            'proposed_mode' => $this->call->proposed_mode,
            'awaiting_confirmation' => $this->call->awaitingConfirmation(),
            'summary' => $this->call->summary,
            'message' => $this->line(),
            'url' => '/employer/applicants',
        ];
    }

    private function line(): string
    {
        // worker_id is a non-null FK that cascades on delete, so the row is
        // gone before the worker ever is.
        $worker = $this->call->worker->name;

        if ($this->call->awaitingConfirmation()) {
            return __(':worker is interested and suggested :when. Confirm to book it.', [
                'worker' => $worker,
                'when' => $this->call->proposed_interview_at?->format('d M, g:i A') ?? '—',
            ]);
        }

        return __(':worker: :outcome.', [
            'worker' => $worker,
            'outcome' => $this->call->outcome?->label() ?? __('no clear answer'),
        ]);
    }
}
