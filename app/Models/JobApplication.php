<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $job_listing_id
 * @property int $worker_id
 * @property string|null $cover_note
 * @property string|null $expected_wage
 * @property ApplicationStatus $status
 * @property bool $contact_unlocked
 * @property Carbon|null $shortlisted_at
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JobApplication extends Model
{
    protected $fillable = [
        'job_listing_id', 'worker_id', 'cover_note', 'expected_wage', 'status', 'contact_unlocked', 'shortlisted_at', 'status_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'contact_unlocked' => 'boolean',
            'shortlisted_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'expected_wage' => 'decimal:2',
        ];
    }

    /**
     * Parcel-style status timeline for the application tracker (web + app).
     * Returns four ordered steps; the frontend maps each `key` to a localized
     * label and renders the icon from `state`.
     *
     * state: done | current | upcoming | rejected | skipped
     *
     * @return array<int, array{key: string, state: string, at: string|null, result: string|null}>
     */
    public function trackingSteps(): array
    {
        $shortlisted = $this->shortlisted_at !== null;
        $decided = in_array($this->status, [ApplicationStatus::Accepted, ApplicationStatus::Rejected, ApplicationStatus::Withdrawn], true);

        $applied = [
            'key' => 'applied',
            'state' => 'done',
            'at' => optional($this->created_at)->toIso8601String(),
            'result' => null,
        ];

        $review = [
            'key' => 'review',
            'state' => ($shortlisted || $decided) ? 'done' : 'current',
            'at' => null,
            'result' => null,
        ];

        if ($shortlisted) {
            $shortlistState = 'done';
        } elseif ($decided) {
            $shortlistState = 'skipped';
        } else {
            $shortlistState = 'upcoming';
        }

        $shortlist = [
            'key' => 'shortlisted',
            'state' => $shortlistState,
            'at' => optional($this->shortlisted_at)->toIso8601String(),
            'result' => null,
        ];

        $decisionState = match (true) {
            $this->status === ApplicationStatus::Accepted => 'done',
            $this->status === ApplicationStatus::Rejected => 'rejected',
            $this->status === ApplicationStatus::Withdrawn => 'done',
            $shortlisted => 'current',
            default => 'upcoming',
        };

        $decision = [
            'key' => 'decision',
            'state' => $decisionState,
            'at' => optional($this->status_changed_at)->toIso8601String(),
            'result' => $decided ? $this->status->value : null,
        ];

        return [$applied, $review, $shortlist, $decision];
    }

    /**
     * Accessor so `->append('tracking_steps')` exposes the timeline in JSON
     * (Inertia props / API resources) without serializing it everywhere.
     *
     * @return array<int, array{key: string, state: string, at: string|null, result: string|null}>
     */
    public function getTrackingStepsAttribute(): array
    {
        return $this->trackingSteps();
    }

    /**
     * @return BelongsTo<JobListing, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'job_listing_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    /**
     * @return HasOne<Escrow, $this>
     */
    public function escrow(): HasOne
    {
        return $this->hasOne(Escrow::class);
    }
}
