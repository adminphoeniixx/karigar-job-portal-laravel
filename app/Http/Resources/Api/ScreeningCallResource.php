<?php

namespace App\Http\Resources\Api;

use App\Models\ScreeningCall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One automated screening call as the employer sees it: what the worker said,
 * when they could come in, and whether that slot still needs confirming.
 *
 * The worker's phone number is not in here. The platform placed the call; the
 * number stays behind the contact-unlock paywall.
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
            // Full transcripts are long and only useful on the detail screen.
            'transcript' => $this->when($request->boolean('with_transcript'), $this->transcript),
        ];
    }
}
