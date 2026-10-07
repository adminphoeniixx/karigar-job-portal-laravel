<?php

namespace App\Services;

use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkerContactUnlock;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The karigars whose numbers an employer account holds, as two lists: the
 * ones unlocked from the Worker Database with the plan, and the applicants
 * to its own jobs. Web and API show the same rows through this.
 *
 * Each list lasts as long as the plan behind it: database contacts need
 * Worker Database access (a job or database plan), applicant contacts a job
 * plan. Without it only the karigars the employer shortlisted or hired stay.
 */
class ContactList
{
    public const SORTS = ['recent', 'oldest', 'name'];

    private User $account;

    public function __construct(User $user)
    {
        $this->account = $user->employerAccount();
    }

    public static function for(User $user): self
    {
        return new self($user);
    }

    /**
     * Karigars unlocked from the Worker Database.
     *
     * @param  array<string, mixed>  $filters  q, skill, state, city, period (all|cycle), sort
     */
    public function database(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->databaseContacts()
            ->with('worker:id,name,email,phone', 'worker.workerProfile', 'unlockedBy:id,name');

        $this->filterWorker($query, $filters);

        if (($filters['period'] ?? 'all') === 'cycle' && ($since = $this->cycleStart())) {
            $query->where('created_at', '>=', $since);
        }

        $this->sort($query, $filters['sort'] ?? 'recent', 'worker_contact_unlocks.created_at');

        return $query->paginate($perPage)->withQueryString()->through(fn (WorkerContactUnlock $unlock) => [
            ...$this->karigar($unlock->worker),
            'unlocked_at' => $unlock->created_at?->toIso8601String(),
            'unlocked_by' => $unlock->unlockedBy?->name,
        ]);
    }

    /**
     * Applicants to this account's jobs whose contact is unlocked, one row per
     * application.
     *
     * @param  array<string, mixed>  $filters  q, skill, state, city, job, stage, sort
     */
    public function applicants(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->applicantContacts()
            ->with('worker:id,name,email,phone', 'worker.workerProfile', 'job:id,title');

        $this->filterWorker($query, $filters);

        if (! empty($filters['job'])) {
            $query->where('job_listing_id', (int) $filters['job']);
        }

        if (! empty($filters['stage']) && $filters['stage'] !== 'all') {
            $query->inStage($filters['stage']);
        }

        $this->sort($query, $filters['sort'] ?? 'recent', 'job_applications.created_at');

        return $query->paginate($perPage)->withQueryString()->through(fn (JobApplication $application) => [
            ...$this->karigar($application->worker),
            'application_id' => $application->id,
            'job' => $application->job?->only('id', 'title'),
            'stage' => $application->stage(),
            'applied_at' => $application->created_at?->toIso8601String(),
        ]);
    }

    /**
     * The plans' unlock allowances and how this cycle's unlocks split between
     * the two lists, each list's size for its tab, and how much of each is
     * hidden because its plan ran out.
     *
     * @return array<string, mixed>
     */
    public function usage(): array
    {
        $wallet = ContactUnlocks::for($this->account);
        $job = $wallet->subscription(Plan::TYPE_JOB);
        $database = $wallet->subscription(Plan::TYPE_DATABASE);

        // Unlocks each plan paid for this cycle, by the list they came from.
        $bySource = [WorkerContactUnlock::SOURCE_DIRECTORY => 0, WorkerContactUnlock::SOURCE_APPLICATION => 0];

        foreach ([WorkerContactUnlock::POOL_JOB => $job, WorkerContactUnlock::POOL_DATABASE => $database] as $pool => $subscription) {
            if ($subscription === null) {
                continue;
            }

            $counts = WorkerContactUnlock::where('employer_id', $this->account->id)
                ->where('pool', $pool)
                ->where('created_at', '>=', $subscription->currentCycleStart())
                ->selectRaw('source, count(*) as total')
                ->groupBy('source')
                ->pluck('total', 'source');

            foreach ($counts as $source => $total) {
                $bySource[$source] = ($bySource[$source] ?? 0) + (int) $total;
            }
        }

        $counts = $this->counts();

        return [
            'plan' => $job?->plan->name,
            'database_plan' => $database?->plan->name,
            'limit' => $wallet->planLimit(),
            'used' => $wallet->unlocksUsed(),
            'remaining' => $wallet->planRemaining(),
            'resets_at' => ($job ?? $database)?->ends_at?->toIso8601String(),
            'pools' => $wallet->pools(),
            'used_database' => $bySource[WorkerContactUnlock::SOURCE_DIRECTORY],
            'used_applicants' => $bySource[WorkerContactUnlock::SOURCE_APPLICATION],
            'has_database_access' => $this->hasDatabaseAccess(),
            'has_job_plan' => $job !== null,
            'job_plan_lapsed' => $this->account->jobPlanLapsed(),
            ...$counts,
            // Unlocked contacts out of sight until the plan behind them is renewed.
            'database_hidden' => $this->allDatabaseContacts()->count() - $counts['database_total'],
            'applicants_hidden' => $this->allApplicantContacts()->count() - $counts['applicants_total'],
        ];
    }

    /**
     * How many karigars each list shows, for the tab labels.
     *
     * @return array{database_total: int, applicants_total: int}
     */
    public function counts(): array
    {
        return [
            'database_total' => $this->databaseContacts()->count(),
            'applicants_total' => $this->applicantContacts()->count(),
        ];
    }

    /**
     * @return Builder<WorkerContactUnlock>
     */
    private function allDatabaseContacts(): Builder
    {
        return WorkerContactUnlock::query()
            ->where('employer_id', $this->account->id)
            ->where('source', WorkerContactUnlock::SOURCE_DIRECTORY)
            ->whereHas('worker');
    }

    /**
     * Database contacts the employer can see now: all of them with Worker
     * Database access, else only karigars it shortlisted or hired.
     *
     * @return Builder<WorkerContactUnlock>
     */
    private function databaseContacts(): Builder
    {
        return $this->allDatabaseContacts()->unless($this->hasDatabaseAccess(), fn (Builder $q) => $q
            ->whereHas('worker.applications', fn (Builder $a) => $a->kept()
                ->whereHas('job', fn (Builder $j) => $j->where('employer_id', $this->account->id))));
    }

    /**
     * @return Builder<JobApplication>
     */
    private function allApplicantContacts(): Builder
    {
        return JobApplication::query()
            ->where('contact_unlocked', true)
            ->whereHas('job', fn ($q) => $q->where('employer_id', $this->account->id))
            ->whereHas('worker');
    }

    /**
     * Applicant contacts the employer can see now: all of them with a job
     * plan, else only the ones it shortlisted or hired.
     *
     * @return Builder<JobApplication>
     */
    private function applicantContacts(): Builder
    {
        return $this->allApplicantContacts()
            ->unless($this->account->hasActiveSubscription(Plan::TYPE_JOB), fn (Builder $q) => $q->kept());
    }

    private function hasDatabaseAccess(): bool
    {
        return $this->account->contactDatabaseQuota() > 0;
    }

    /**
     * The account's jobs, for the applicants list's job filter.
     *
     * @return list<array{id: int, title: string}>
     */
    public function jobs(): array
    {
        return JobListing::where('employer_id', $this->account->id)
            ->latest()
            ->get(['id', 'title'])
            ->map(fn (JobListing $job) => ['id' => $job->id, 'title' => $job->title])
            ->all();
    }

    /**
     * Search and filter on the karigar behind the row (its `worker` relation).
     *
     * @param  Builder<WorkerContactUnlock>|Builder<JobApplication>  $query
     * @param  array<string, mixed>  $filters
     */
    private function filterWorker(Builder $query, array $filters): void
    {
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%'.mb_strtolower($q).'%';

            $query->whereHas('worker', fn ($w) => $w->where(fn ($match) => $match
                ->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhere('phone', 'like', $like)
                ->orWhereHas('workerProfile', fn ($p) => $p->where('phone', 'like', $like))));
        }

        if (! empty($filters['skill'])) {
            // Match a whole skill, case-insensitively, inside the JSON list.
            $needle = '%'.mb_strtolower(json_encode($filters['skill'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'%';

            $query->whereHas('worker.workerProfile', fn ($p) => $p->whereRaw('LOWER(CAST(skills AS TEXT)) LIKE ?', [$needle]));
        }

        foreach (['state', 'city'] as $field) {
            if (! empty($filters[$field])) {
                $query->whereHas('worker.workerProfile', fn ($p) => $p->where($field, $filters[$field]));
            }
        }
    }

    /**
     * @param  Builder<WorkerContactUnlock>|Builder<JobApplication>  $query
     */
    private function sort(Builder $query, string $sort, string $dateColumn): void
    {
        match ($sort) {
            'oldest' => $query->orderBy($dateColumn),
            'name' => $query->orderBy(
                User::select('name')->whereColumn('users.id', $query->getModel()->getTable().'.worker_id')
            ),
            default => $query->orderByDesc($dateColumn),
        };

        $query->orderByDesc($query->getModel()->getTable().'.id');
    }

    /**
     * The karigar's side of a row: who they are and how to reach them.
     *
     * @return array<string, mixed>
     */
    private function karigar(User $worker): array
    {
        $profile = $worker->workerProfile;

        return [
            'worker_id' => $worker->id,
            'profile_id' => $profile?->id,
            'name' => $worker->name,
            'avatar_url' => $profile?->avatar_url,
            'phone' => $profile?->phone ?: $worker->phone,
            'email' => $worker->contactEmail(),
            'city' => $profile?->city,
            'state' => $profile?->state,
            'skills' => $profile?->skills ?? [],
            'experience_years' => $profile?->experience_years,
            'expected_wage' => $profile?->expected_wage,
            'wage_type' => $profile?->wage_type,
        ];
    }

    /**
     * Where "unlocked this billing cycle" starts: the database plan's cycle
     * when the account holds one, since it pays for database unlocks first.
     */
    private function cycleStart(): ?CarbonInterface
    {
        return ($this->account->activeSubscription(Plan::TYPE_DATABASE) ?? $this->account->activeSubscription())
            ?->currentCycleStart();
    }
}
