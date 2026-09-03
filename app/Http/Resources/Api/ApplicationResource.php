<?php

namespace App\Http\Resources\Api;

use App\Enums\ApplicationStatus;
use App\Models\JobApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobApplication
 */
class ApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Preloaded by the applications list so a page costs one query; a
        // single application falls back to asking for itself.
        $hasReviewed = $this->resource->getAttribute('has_reviewed') !== null
            ? (bool) $this->resource->getAttribute('has_reviewed')
            : $this->workerHasReviewed();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'cover_note' => $this->cover_note,
            'expected_wage' => $this->expected_wage,
            'shortlisted_at' => $this->shortlisted_at?->toIso8601String(),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            // Set once the employer schedules an interview.
            'interview' => $this->interview_at !== null ? [
                'at' => $this->interview_at->toIso8601String(),
                'at_label' => $this->interview_at->format('d M Y, g:i A'),
                'mode' => $this->interview_mode,
                'note' => $this->interview_note,
            ] : null,
            // Set once the employer accepts with hire details.
            'offer' => $this->offered_wage !== null || $this->start_date !== null ? [
                'wage' => $this->offered_wage,
                'start_date' => $this->start_date?->toDateString(),
                'message' => $this->offer_message,
            ] : null,
            // Rating the employer: only once the job was accepted, and only
            // once — the same rule the review endpoint enforces.
            'has_reviewed' => $hasReviewed,
            'can_review' => $this->status === ApplicationStatus::Accepted && ! $hasReviewed,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_ago' => $this->created_at?->diffForHumans(),
            // Parcel-style timeline for the app tracker (see trackingSteps()).
            'tracking_steps' => $this->trackingSteps(),
            'job' => $this->whenLoaded('job', fn () => [
                'id' => $this->job->id,
                'title' => $this->job->title,
                'city' => $this->job->city,
                'state' => $this->job->state,
                'location_label' => collect([$this->job->city, $this->job->state])->filter()->join(', ') ?: null,
                'employer' => $this->job->relationLoaded('employer') && $this->job->employer ? [
                    'id' => $this->job->employer->id,
                    'name' => $this->job->employer->name,
                ] : null,
            ]),
        ];
    }
}
