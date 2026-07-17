<?php

namespace App\Http\Resources\Api;

use App\Enums\ApplicationStatus;
use App\Models\JobApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One applicant as the employer sees them. Contact details are only revealed
 * once the application's contact has been unlocked. `stage` collapses status +
 * shortlist into the three buckets the app shows: pending / shortlisted / hired.
 *
 * @mixin JobApplication
 */
class ApplicantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $worker = $this->worker;
        $profile = $worker?->workerProfile;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'stage' => $this->stage(),
            'shortlisted' => $this->shortlisted_at !== null,
            'cover_note' => $this->cover_note,
            'expected_wage' => $this->expected_wage,
            'contact_unlocked' => $this->contact_unlocked,
            'created_ago' => $this->created_at?->diffForHumans(),
            'created_at' => $this->created_at?->toIso8601String(),
            'job' => $this->whenLoaded('job', fn () => [
                'id' => $this->job->id,
                'title' => $this->job->title,
            ]),
            'worker' => $worker ? [
                'id' => $worker->id,
                'name' => $worker->name,
                'rating' => $worker->averageRating(),
                'reviews_count' => $worker->reviewsReceived()->count(),
                'avatar_url' => $profile?->avatar_url,
                'bio' => $profile?->bio,
                'skills' => $profile?->skills ?? [],
                'spoken_languages' => $profile?->spoken_languages ?? [],
                'experience_years' => $profile?->experience_years,
                'city' => $profile?->city,
                'state' => $profile?->state,
                'expected_wage' => $profile?->expected_wage,
                'wage_type' => $profile?->wage_type,
                'available' => (bool) ($profile?->available ?? false),
                'verified' => $worker->kyc?->status->value === 'verified',
                // Only revealed once the contact has been unlocked.
                'phone' => $this->contact_unlocked ? ($profile?->phone ?? $worker->phone) : null,
                'email' => $this->contact_unlocked ? $worker->email : null,
            ] : null,
        ];
    }

    /**
     * Collapse status + shortlist flag into the app's three-tab model.
     */
    protected function stage(): string
    {
        return match (true) {
            $this->status === ApplicationStatus::Accepted => 'hired',
            $this->status === ApplicationStatus::Rejected => 'rejected',
            $this->shortlisted_at !== null => 'shortlisted',
            default => 'pending',
        };
    }
}
