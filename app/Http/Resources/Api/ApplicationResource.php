<?php

namespace App\Http\Resources\Api;

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
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'cover_note' => $this->cover_note,
            'expected_wage' => $this->expected_wage,
            'shortlisted_at' => $this->shortlisted_at?->toIso8601String(),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
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
