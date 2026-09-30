<?php

namespace App\Services;

use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Which of a job's applicants the employer may see.
 *
 * - Applicants arrive in batches, oldest first: the first 20, and 15 more
 *   each time the employer has decided on every one shown so far
 *   (shortlisted, interviewed, hired or rejected). Both sizes are admin
 *   settings. A batch once shown stays shown.
 * - Only with an active job plan. Without one (the plan ran out, or the
 *   free first post) the employer sees how many applied, not who.
 * - Shortlisted, interviewed and hired applicants are always visible: they
 *   are the employer's own picks and stay with it when the plan runs out.
 */
class ApplicantAccess
{
    public const FIRST_BATCH_KEY = 'applicant_first_batch';

    public const NEXT_BATCH_KEY = 'applicant_next_batch';

    private User $account;

    private ?bool $hasJobPlan = null;

    /** @var array<int, array{released: int, cutoff: int}> per job: count released, id of the last one */
    private array $batches = [];

    public function __construct(User $user)
    {
        $this->account = $user->employerAccount();
    }

    public static function for(User $user): self
    {
        return new self($user);
    }

    public static function firstBatch(): int
    {
        return max(1, Setting::int(self::FIRST_BATCH_KEY, 20));
    }

    public static function nextBatch(): int
    {
        return max(1, Setting::int(self::NEXT_BATCH_KEY, 15));
    }

    public function hasJobPlan(): bool
    {
        return $this->hasJobPlan ??= $this->account->hasActiveSubscription(Plan::TYPE_JOB);
    }

    /**
     * How many of the job's applicants, oldest first, have been released to
     * the employer. Grows by a batch whenever every released applicant has
     * been decided, and is stored so it never shrinks.
     */
    public function released(JobListing $job): int
    {
        if (isset($this->batches[$job->id])) {
            return $this->batches[$job->id]['released'];
        }

        $applications = $job->applications()->orderBy('id')->get(['id', 'status', 'shortlisted_at', 'interview_at']);
        $total = $applications->count();
        $released = min($total, max($job->applicants_released, self::firstBatch()));

        while ($released < $total && $applications->take($released)->every->isDecided()) {
            $released = min($total, $released + self::nextBatch());
        }

        if ($released > $job->applicants_released) {
            // A plain update: no timestamps, no search sync for a counter.
            JobListing::whereKey($job->id)->update(['applicants_released' => $released]);
            $job->applicants_released = $released;
        }

        $this->batches[$job->id] = [
            'released' => $released,
            'cutoff' => $released > 0 ? (int) $applications[$released - 1]->id : 0,
        ];

        return $released;
    }

    /**
     * Narrow a query of one job's applications to the ones the employer may see.
     *
     * @param  Builder<JobApplication>|HasMany<JobApplication, JobListing>  $query
     */
    public function constrain(Builder|HasMany $query, JobListing $job): void
    {
        $cutoff = $this->hasJobPlan() ? $this->cutoff($job) : 0;

        $query->where(fn (Builder $q) => $q
            ->where('job_applications.id', '<=', $cutoff)
            ->orWhere(fn (Builder $kept) => $kept->kept()));
    }

    public function isVisible(JobApplication $application): bool
    {
        if ($application->isKept()) {
            return true;
        }

        return $this->hasJobPlan() && $application->id <= $this->cutoff($application->job);
    }

    /**
     * What the applicants screen tells the employer about the ones it cannot
     * see yet, and why.
     *
     * @return array{total: int, visible: int, hidden: int, reason: string|null, undecided: int, next_batch: int}
     */
    public function summary(JobListing $job): array
    {
        $total = $job->applications()->count();
        $visible = tap($job->applications(), fn ($q) => $this->constrain($q, $job))->count();
        $hidden = $total - $visible;

        $reason = match (true) {
            $hidden === 0 => null,
            $this->hasJobPlan() => 'batch',
            $this->account->jobPlanLapsed() => 'plan_expired',
            default => 'no_plan',
        };

        $undecided = 0;
        $next = 0;

        if ($reason === 'batch') {
            $released = $this->released($job);
            $undecided = $job->applications()->orderBy('id')->limit($released)->get()->reject->isDecided()->count();
            $next = min(self::nextBatch(), $total - $released);
        }

        return [
            'total' => $total,
            'visible' => $visible,
            'hidden' => $hidden,
            // null (nothing hidden) | batch | plan_expired | no_plan
            'reason' => $reason,
            // In a batch: how many shown applicants still need a decision
            // before the next `next_batch` open.
            'undecided' => $undecided,
            'next_batch' => $next,
        ];
    }

    private function cutoff(JobListing $job): int
    {
        $this->released($job);

        return $this->batches[$job->id]['cutoff'];
    }
}
