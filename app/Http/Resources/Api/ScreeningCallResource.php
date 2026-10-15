<?php

namespace App\Http\Resources\Api;

use App\Enums\ScreeningCallStatus;
use App\Models\ScreeningCall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One automated screening call as the employer sees it: what the worker said,
 * when they could come in, and whether that slot still needs confirming.
 *
 * The worker's phone number is not in here. The platform placed the call; the
 * number comes with the applicant (ApplicantResource).
 *
 * @mixin ScreeningCall
 */
class ScreeningCallResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->job_application_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'attempt' => $this->attempt,
            // When a booked call will ring (status "scheduled"), in IST for the label.
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'scheduled_label' => $this->scheduled_at?->timezone(config('app.display_timezone'))->format('d M Y, g:i A'),
            // manual | auto (after auto-shortlist) | retry | invite
            'source' => $this->source,
            'can_cancel' => $this->status === ScreeningCallStatus::Scheduled,
            'language' => $this->language,
            'outcome' => $this->outcome?->value,
            'outcome_label' => $this->outcome?->label(),
            'summary' => $this->summary,
            'proposed_interview_at' => $this->proposed_interview_at?->toIso8601String(),
            'proposed_interview_label' => $this->proposed_interview_at?->format('d M Y, g:i A'),
            'proposed_mode' => $this->proposed_mode,
            'employer_confirmed' => $this->employer_confirmed,
            'awaiting_confirmation' => $this->awaitingConfirmation(),
            'duration_seconds' => $this->duration_seconds,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_ago' => $this->created_at?->diffForHumans(),
            // Whether "View conversation" has anything to show.
            'has_conversation' => $this->hasConversation(),
            // Full transcripts are long and only useful on the detail screen.
            'transcript' => $this->when($request->boolean('with_transcript'), $this->transcript),
            // The same transcript as chat turns: speaker "agent" or "karigar".
            'conversation' => $this->when($request->boolean('with_transcript'), fn () => $this->conversation()),
        ];
    }
}
