<?php

namespace App\Http\Resources\Api;

use App\Models\JobListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobListing
 */
class JobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'skills' => $this->skills ?? [],
            'city' => $this->city,
            'state' => $this->state,
            'location_label' => collect([$this->city, $this->state])->filter()->join(', ') ?: null,
            'wage_min' => $this->wage_min,
            'wage_max' => $this->wage_max,
            'wage_type' => $this->wage_type,
            'wage_label' => $this->wageLabel(),
            'vacancies' => $this->vacancies,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_ago' => $this->created_at?->diffForHumans(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'employer' => $this->whenLoaded('employer', fn () => [
                'id' => $this->employer->id,
                'name' => $this->employer->name,
            ]),
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
