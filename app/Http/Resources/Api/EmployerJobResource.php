<?php

namespace App\Http\Resources\Api;

use App\Models\JobListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A job as its owner (the employer) sees it — includes applicant counts and
 * the full editable field set. Counts are eager-loaded by the controller via
 * withCount(); missing counts fall back to a query.
 *
 * @mixin JobListing
 */
class EmployerJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'skills' => $this->skills ?? [],
            'wage_min' => $this->wage_min,
            'wage_max' => $this->wage_max,
            'wage_type' => $this->wage_type,
            'wage_label' => $this->wageLabel(),
            'city' => $this->city,
            'state' => $this->state,
            'location_label' => collect([$this->city, $this->state])->filter()->join(', ') ?: null,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'vacancies' => $this->vacancies,
            'shift' => $this->shift,
            'perks' => $this->perks ?? [],
            'contact_mode' => $this->contact_mode,
            'contact_phone' => $this->contact_phone,
            'requires_worker_fee' => $this->requires_worker_fee,
            'worker_fee_amount' => $this->worker_fee_amount,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'stats' => [
                'applicants' => (int) ($this->applications_count ?? $this->applications()->count()),
                'shortlisted' => (int) ($this->shortlisted_count ?? $this->applications()->whereNotNull('shortlisted_at')->count()),
                'hired' => (int) ($this->hired_count ?? $this->applications()->where('status', 'accepted')->count()),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'created_ago' => $this->created_at?->diffForHumans(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }

    protected function wageLabel(): string
    {
        if ($this->wage_min === null && $this->wage_max === null) {
            return 'Not disclosed';
        }

        $range = collect([$this->wage_min, $this->wage_max])
            ->filter()
            ->map(fn ($w) => (string) (int) $w)
            ->join('–');

        return '₹'.$range.($this->wage_type ? ' / '.$this->wage_type : '');
    }
}
