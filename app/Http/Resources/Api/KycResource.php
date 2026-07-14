<?php

namespace App\Http\Resources\Api;

use App\Models\KycDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Only masked identity values are ever returned — raw numbers/paths are hidden.
 *
 * @mixin KycDocument
 */
class KycResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'masked_pan' => $this->masked_pan,
            'masked_aadhaar' => $this->masked_aadhaar,
            'remarks' => $this->remarks,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'submitted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
