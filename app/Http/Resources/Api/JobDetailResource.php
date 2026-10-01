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
            // Pre-formatted so every client renders the same string without
            // re-deriving it from min/max/type. "Not disclosed" when unset.
            'wage_label' => $this->resource->wageLabel(),
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'location_label' => collect([$this->address, $this->city, $this->state])->filter()->join(', ') ?: null,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'vacancies' => $this->vacancies,
            'experience_min' => $this->experience_min,
            'experience_max' => $this->experience_max,
            'experience_label' => $this->resource->experienceLabel(),
            'shift' => $this->shift,
            'shift_start' => $this->shift_start,
            'shift_end' => $this->shift_end,
            'shift_hours_label' => $this->resource->shiftHoursLabel(),
            'perks' => $this->perks ?? [],
            'contact_mode' => $this->contact_mode,
            // Only exposed when the employer allows calling: the number, and
            // who picks up.
            'contact_phone' => $this->when($canCall, $this->contact_phone),
            'contact_name' => $this->when($canCall, $this->contact_name),
            'contact_designation' => $this->when($canCall, $this->contact_designation),
            'requires_worker_fee' => $this->requires_worker_fee,
            'worker_fee_amount' => $this->worker_fee_amount,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_ago' => $this->created_at?->diffForHumans(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'employer' => [
                'id' => $this->employer->id,
                'name' => $this->employer->name,
                // Business verified by the admin; false while verification is switched off.
                'verified' => $this->employer->isKycVerified(),
            ],
        ];
    }
}
