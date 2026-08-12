<?php

namespace App\Http\Resources\Api;

use App\Models\CallSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One call as either participant sees it — used for both the live call and the
 * call-history list. `direction` and `counterpart` are rendered from the point
 * of view of whoever is asking.
 *
 * No phone number ever appears here. That is the whole point of in-app calling.
 *
 * @mixin CallSession
 */
class CallSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $counterpart = $viewer instanceof User ? $this->counterpartFor($viewer) : null;

        // Workers carry an avatar, employers a company logo — different
        // models, so resolve the URL before building the payload.
        $avatarUrl = match (true) {
            $counterpart === null => null,
            $counterpart->isWorker() => $counterpart->workerProfile?->avatar_url,
            default => $counterpart->employerProfile?->logo_url,
        };

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'direction' => $viewer instanceof User ? $this->directionFor($viewer) : null,
            'channel' => $this->channel,
            'ended_reason' => $this->ended_reason,
            'duration_seconds' => $this->duration_seconds,
            'answered_at' => $this->answered_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_ago' => $this->created_at?->diffForHumans(),
            'job_application_id' => $this->job_application_id,
            'counterpart' => $counterpart ? [
                'id' => $counterpart->id,
                'name' => $counterpart->name,
                'role' => $counterpart->role->value,
                'avatar_url' => $avatarUrl,
            ] : null,
        ];
    }
}
