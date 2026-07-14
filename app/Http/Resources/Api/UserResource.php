<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'locale' => $this->locale,
            'avatar_url' => $this->workerProfile?->avatar_url,
            'rating' => [
                'average' => $this->averageRating(),
                'count' => $this->reviewsReceived()->count(),
            ],
        ];
    }
}
