<?php

namespace App\Http\Resources\Api;

use App\Models\EmployerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployerProfile
 */
class EmployerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user?->name,
            'company_name' => $this->company_name,
            'gstin' => $this->gstin,
            'phone' => $this->phone ?? $this->user?->phone,
            'about' => $this->about,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'location_label' => collect([$this->city, $this->state])->filter()->join(', ') ?: null,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'logo_url' => $this->logo_url,
            'verified' => $this->user?->kyc?->status->value === 'verified',
            'rating' => [
                'average' => $this->user?->averageRating() ?? 0.0,
                'count' => $this->user ? $this->user->reviewsReceived()->count() : 0,
            ],
            'completion' => $this->completionPercent(),
        ];
    }

    /**
     * Rough profile-completeness score for the dashboard nudge.
     */
    protected function completionPercent(): int
    {
        $fields = [
            $this->company_name, $this->phone ?? $this->user?->phone, $this->about,
            $this->address, $this->city, $this->state, $this->latitude, $this->gstin,
        ];
        $filled = collect($fields)->filter(fn ($v) => $v !== null && $v !== '')->count();

        return (int) round($filled / count($fields) * 100);
    }
}
