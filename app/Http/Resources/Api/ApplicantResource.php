<?php

namespace App\Http\Resources\Api;

use App\Enums\ApplicationStatus;
use App\Models\JobApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One applicant as the employer sees them. Contact details are only revealed
 * once the application's contact has been unlocked. `stage` collapses status,
 * shortlist and interview into the app's pipeline tabs:
 * pending / shortlisted / interview / hired / rejected.
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
            // Hire sheet: what was offered when the applicant was accepted.
            'offer' => $this->offered_wage !== null || $this->start_date !== null || $this->offer_message !== null ? [
                'wage' => $this->offered_wage,
                'start_date' => $this->start_date?->toDateString(),
                'message' => $this->offer_message,
            ] : null,
            // Interview sheet: the scheduled slot, if any.
            'interview' => $this->interview_at !== null ? [
                'at' => $this->interview_at->toIso8601String(),
                'at_label' => $this->interview_at->format('d M Y, g:i A'),
                'mode' => $this->interview_mode,
                'note' => $this->interview_note,
            ] : null,
            // Uploaded resume, when the worker has one. The PDF is private, so
            // only the filename and upload time travel in the payload.
            'resume' => $profile?->resume_path !== null ? [
                'name' => $profile->resume_name,
                'uploaded_at' => $profile->resume_uploaded_at?->toIso8601String(),
                'download_url' => route('applicants.resume', $this->id),
            ] : null,
            // AI match scoring (null until the ScoreApplication job has run).
            'ai' => $this->ai_scored_at !== null ? [
                'score' => $this->ai_score,
                'recommendation' => $this->ai_recommendation,
                'summary' => $this->ai_summary,
                'matched_skills' => $this->ai_matched_skills ?? [],
                'red_flags' => $this->ai_red_flags ?? [],
            ] : null,
            'created_ago' => $this->created_at?->diffForHumans(),
            'created_at' => $this->created_at?->toIso8601String(),
            'tracking_steps' => $this->trackingSteps(),
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
                'verified' => $worker->isKycVerified(),
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
            $this->interview_at !== null => 'interview',
            $this->shortlisted_at !== null => 'shortlisted',
            default => 'pending',
        };
    }
}
