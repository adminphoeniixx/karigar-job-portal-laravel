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
            // The street address the map pin is placed from.
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'location_label' => collect([$this->city, $this->state])->filter()->join(', ') ?: null,
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
            'contact_phone' => $this->contact_phone,
            // Who picks up when a karigar calls.
            'contact_name' => $this->contact_name,
            'contact_designation' => $this->contact_designation,
            // Set on a repost: the job it was copied from.
            'reposted_from_id' => $this->reposted_from_id,
            'requires_worker_fee' => $this->requires_worker_fee,
            'worker_fee_amount' => $this->worker_fee_amount,
            // The employer's AI switches; `ai` in form-options says whether the
            // admin has the features on at all.
            'ai_shortlist_enabled' => (bool) ($this->ai_shortlist_enabled ?? true),
            'ai_call_enabled' => (bool) ($this->ai_call_enabled ?? true),
            'status' => $this->status->value,
            // Never been live. Publishing it (status → active) is checked against
            // the plan's job posts; saving it as a draft never is.
            'is_draft' => $this->published_at === null,
            'published_at' => $this->published_at?->toIso8601String(),
            'status_label' => $this->status->label(),
            'stats' => [
                'views' => (int) $this->views_count,
                'applicants' => (int) ($this->applications_count ?? $this->applications()->count()),
                'shortlisted' => (int) ($this->shortlisted_count ?? $this->applications()->whereNotNull('shortlisted_at')->count()),
                'interview' => (int) ($this->interview_count ?? $this->applications()->whereNotNull('interview_at')->whereNotIn('status', ['accepted', 'rejected'])->count()),
                'hired' => (int) ($this->hired_count ?? $this->applications()->where('status', 'accepted')->count()),
            ],
            'boost' => [
                'active' => $this->isBoosted(),
                'tier' => $this->boost_tier,
                'until' => $this->boosted_until?->toIso8601String(),
            ],
            'share_url' => url("/jobs/{$this->id}"),
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
