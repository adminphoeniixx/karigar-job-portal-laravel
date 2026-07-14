<?php

namespace App\Http\Resources\Api;

use App\Models\JobListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full job detail for the job-show screen. The controller attaches
 * `employer_rating`, `application` and `is_saved` via ->additional().
 *
 * @mixin JobListing
 */
class JobDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canCall = $this->contact_mode !== 'apply';

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'skills' => $this->skills ?? [],
            'wage_min' => $this->wage_min,
            'wage_max' => $this->wage_max,
            'wage_type' => $this->wage_type,
            'city' => $this->city,
            'state' => $this->state,
            'location_label' => collect([$this->city, $this->state])->filter()->join(', ') ?: null,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'vacancies' => $this->vacancies,
            'shift' => $this->shift,
            'perks' => $this->perks ?? [],
            'contact_mode' => $this->contact_mode,
            // Only exposed when the employer allows calling.
            'contact_phone' => $this->when($canCall, $this->contact_phone),
            'requires_worker_fee' => $this->requires_worker_fee,
            'worker_fee_amount' => $this->worker_fee_amount,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_ago' => $this->created_at?->diffForHumans(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'employer' => [
                'id' => $this->employer->id,
                'name' => $this->employer->name,
            ],
        ];
    }
}
