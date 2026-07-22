<?php

namespace App\Http\Resources\Api;

use App\Models\WorkerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkerProfile
 */
class WorkerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user?->name,
            // Hide the phone-OTP placeholder (<phone>@phone.karigar) so the app
            // shows the email field as empty until the worker sets a real one.
            'email' => str_ends_with((string) $this->user?->email, '@phone.karigar') ? null : $this->user?->email,
            'phone' => $this->phone ?? $this->user?->phone,
            'gender' => $this->gender,
            'skills' => $this->skills ?? [],
            'experience_years' => $this->experience_years,
            'education' => $this->education,
            'spoken_languages' => $this->spoken_languages ?? [],
            'bio' => $this->bio,
            'expected_wage' => $this->expected_wage,
            'wage_type' => $this->wage_type,
            'city' => $this->city,
            'state' => $this->state,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'travel_radius_km' => $this->travel_radius_km,
            'available' => (bool) $this->available,
            'payout_upi' => $this->payout_upi,
            'avatar_url' => $this->avatar_url,
            'completion' => $this->completionPercent(),
        ];
    }

    /**
     * Rough profile-completeness score for the dashboard nudge.
     */
    protected function completionPercent(): int
    {
        $fields = [
            $this->user?->name, $this->phone ?? $this->user?->phone, $this->gender,
            ! empty($this->skills), $this->experience_years !== null, $this->education,
            ! empty($this->spoken_languages), $this->bio, $this->expected_wage,
            $this->city, $this->latitude, $this->payout_upi,
        ];
        $filled = collect($fields)->filter(fn ($v) => $v !== null && $v !== false && $v !== '')->count();

        return (int) round($filled / count($fields) * 100);
    }
}
