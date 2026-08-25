<?php

namespace App\Notifications;

use App\Models\ScreeningCall;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\TemplatedMailChannel;
use App\Notifications\Messages\FcmMessage;
use App\Notifications\Messages\TemplatedMailMessage;
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
        return ['database', FcmChannel::class, TemplatedMailChannel::class];
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
                'url' => $this->url(),
            ],
        );
    }

    public function toTemplatedMail(object $notifiable): TemplatedMailMessage
    {
        return TemplatedMailMessage::create('screening_call_completed', [
            'employer_name' => $notifiable->name ?? '',
            'worker_name' => $this->call->worker->name,
            'job_title' => $this->call->application?->job?->title ?? '',
            'outcome' => $this->call->outcome?->label() ?? __('No clear answer'),
            'summary' => $this->call->summary ?: __('No summary was captured for this call.'),
            // Only set when the worker actually offered a time. Templates
            // cannot branch, so the no-slot case needs words of its own rather
            // than a blank row in the table.
            'proposed_interview_at' => $this->call->awaitingConfirmation()
                ? ($this->call->proposed_interview_at?->format('l, d M Y, g:i A') ?? __('None given'))
                : __('None given'),
            'action_url' => url($this->url()),
        ]);
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
            'url' => $this->url(),
        ];
    }

    /**
     * Straight to the applicants list for the job this call was about — that
     * is where the proposed slot is confirmed.
     */
    private function url(): string
    {
        $jobId = $this->call->application?->job_listing_id;

        return $jobId !== null ? "/employer/jobs/{$jobId}/applicants" : '/employer/jobs';
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
