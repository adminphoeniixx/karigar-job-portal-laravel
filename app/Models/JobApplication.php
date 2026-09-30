<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Api\Worker\ReviewController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string|null $offered_wage
 * @property Carbon|null $start_date
 * @property string|null $offer_message
 * @property Carbon|null $interview_at
 * @property string|null $interview_mode
 * @property string|null $interview_note
 * @property Carbon|null $shortlisted_at
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JobApplication extends Model
{
    /**
     * The employer's pipeline, in order: New → Shortlisted → Interview →
     * Hired / Rejected. Every application is in exactly one.
     */
    public const STAGES = ['pending', 'shortlisted', 'interview', 'hired', 'rejected'];

    protected $fillable = [
        'job_listing_id', 'worker_id', 'cover_note', 'expected_wage', 'status', 'contact_unlocked', 'shortlisted_at', 'status_changed_at',
        'offered_wage', 'start_date', 'offer_message',
        'interview_at', 'interview_mode', 'interview_note',
        'ai_score', 'ai_recommendation', 'ai_summary', 'ai_matched_skills', 'ai_red_flags', 'ai_scored_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'contact_unlocked' => 'boolean',
            'shortlisted_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'expected_wage' => 'decimal:2',
            'offered_wage' => 'decimal:2',
            'start_date' => 'date',
            'interview_at' => 'datetime',
            'ai_score' => 'integer',
            'ai_matched_skills' => 'array',
            'ai_red_flags' => 'array',
            'ai_scored_at' => 'datetime',
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
     * Which of {@see STAGES} this application is in: status and the shortlist
     * and interview dates collapsed into one.
     */
    public function stage(): string
    {
        return match (true) {
            $this->status === ApplicationStatus::Accepted => 'hired',
            $this->status === ApplicationStatus::Rejected => 'rejected',
            $this->interview_at !== null => 'interview',
            $this->shortlisted_at !== null => 'shortlisted',
            default => 'pending',
        };
    }

    /**
     * Constrain a query to one of {@see STAGES}. The stages are exclusive, so
     * per-stage counts add up.
     *
     * @param  Builder<JobApplication>  $query
     */
    public function scopeInStage(Builder $query, string $stage): void
    {
        match ($stage) {
            'pending' => $query->where('status', ApplicationStatus::Pending)
                ->whereNull('shortlisted_at')
                ->whereNull('interview_at'),
            'shortlisted' => $query->whereNotNull('shortlisted_at')
                ->whereNull('interview_at')
                ->whereNotIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Rejected]),
            'interview' => $query->whereNotNull('interview_at')
                ->whereNotIn('status', [ApplicationStatus::Accepted, ApplicationStatus::Rejected]),
            'hired' => $query->where('status', ApplicationStatus::Accepted),
            'rejected' => $query->where('status', ApplicationStatus::Rejected),
            default => $query,
        };
    }

    /**
     * Shortlisted, in interview or hired: the applicants an employer keeps
     * seeing after its plan runs out, and past the batch it has been shown.
     */
    public function isKept(): bool
    {
        return $this->shortlisted_at !== null
            || $this->interview_at !== null
            || $this->status === ApplicationStatus::Accepted;
    }

    /**
     * @param  Builder<JobApplication>  $query
     */
    public function scopeKept(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereNotNull('shortlisted_at')
            ->orWhereNotNull('interview_at')
            ->orWhere('status', ApplicationStatus::Accepted));
    }

    /**
     * Whether the employer has acted on this applicant (or the karigar
     * withdrew): what opens the next batch of applicants.
     */
    public function isDecided(): bool
    {
        return $this->isKept()
            || in_array($this->status, [ApplicationStatus::Rejected, ApplicationStatus::Withdrawn], true);
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
    /**
     * Has this application's worker already reviewed the job's employer?
     *
     * Mirrors the uniqueness rule in {@see ReviewController::store()}
     * — one review per reviewer→reviewee per job. Prefer the `has_reviewed`
     * attribute the applications list preloads; this is the fallback for a
     * single application, where one extra query is fine.
     */
    public function workerHasReviewed(): bool
    {
        $this->loadMissing('job');

        if ($this->job === null) {
            return false;
        }

        return Review::where('reviewer_id', $this->worker_id)
            ->where('reviewee_id', $this->job->employer_id)
            ->where('job_listing_id', $this->job_listing_id)
            ->exists();
    }

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

    /**
     * Automated screening calls placed to the worker about this application,
     * including the attempts nobody picked up.
     *
     * @return HasMany<ScreeningCall, $this>
     */
    public function screeningCalls(): HasMany
    {
        return $this->hasMany(ScreeningCall::class);
    }
}
