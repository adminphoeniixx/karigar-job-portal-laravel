<?php

namespace App\Services;

use App\Models\JobApplication;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkerContactUnlock;

/**
 * The employer's contact unlocks: how many karigar numbers the plans the
 * account holds let it reveal. There is no wallet of bought credits; unlocks
 * come only from plans:
 *
 *  - the job plan's contact-unlock allowance (applicants), and
 *  - the database plan's allowance (the Worker Database), when the account
 *    holds one.
 *
 * Each plan's allowance renews on its own billing cycle and counts the unlocks
 * it paid for since that cycle began; a limit of 0 means the plan does not
 * meter unlocks. A Worker Database unlock spends the database plan first, an
 * applicant unlock the job plan first, then the other plan. Without a plan
 * nothing unlocks.
 *
 * Unlocks count karigars, not clicks: a karigar's first unlock is recorded as
 * a WorkerContactUnlock and never charged again. Whether the number still
 * shows is up to the plans the account holds now (see {@see contactVisible()}).
 */
class ContactUnlocks
{
    private User $account;

    /** @var array<string, Subscription|null> */
    private array $subscriptions = [];

    /** @var array<string, array{plan: string, limit: int, used: int, remaining: int|null, resets_at: string|null}>|null */
    private ?array $pools = null;

    public function __construct(User $user)
    {
        $this->account = $user->employerAccount();
    }

    public static function for(User $user): self
    {
        return new self($user);
    }

    /**
     * The account's active subscription of one plan type, looked up once.
     */
    public function subscription(string $type = Plan::TYPE_JOB): ?Subscription
    {
        if (! array_key_exists($type, $this->subscriptions)) {
            $this->subscriptions[$type] = $this->account->activeSubscription($type);
        }

        return $this->subscriptions[$type];
    }

    /**
     * The allowance of each plan the account holds, this cycle.
     *
     * @return array<string, array{plan: string, limit: int, used: int, remaining: int|null, resets_at: string|null}>
     */
    public function pools(): array
    {
        if ($this->pools !== null) {
            return $this->pools;
        }

        $pools = [];

        foreach ([WorkerContactUnlock::POOL_JOB => Plan::TYPE_JOB, WorkerContactUnlock::POOL_DATABASE => Plan::TYPE_DATABASE] as $pool => $type) {
            $subscription = $this->subscription($type);

            if ($subscription === null) {
                continue;
            }

            $limit = $subscription->plan->contactUnlockLimit();
            $used = WorkerContactUnlock::where('employer_id', $this->account->id)
                ->where('pool', $pool)
                ->where('created_at', '>=', $subscription->currentCycleStart())
                ->count();

            $pools[$pool] = [
                'plan' => $subscription->plan->name,
                'limit' => $limit,
                'used' => $used,
                // null: this plan does not meter unlocks.
                'remaining' => $limit > 0 ? max($limit - $used, 0) : null,
                'resets_at' => $subscription->ends_at?->toIso8601String(),
            ];
        }

        return $this->pools = $pools;
    }

    /**
     * The plans' allowances added up; 0 without a plan.
     */
    public function planLimit(): int
    {
        return collect($this->pools())->sum('limit');
    }

    /**
     * Unlocks the plans paid for this cycle.
     */
    public function unlocksUsed(): int
    {
        return collect($this->pools())->sum('used');
    }

    /**
     * Unlocks left on the plans, or null when a plan does not meter them.
     * Zero without a plan.
     */
    public function planRemaining(): ?int
    {
        $pools = collect($this->pools());

        return $pools->contains(fn (array $pool) => $pool['remaining'] === null)
            ? null
            : $pools->sum('remaining');
    }

    /**
     * Karigars (worker user ids) this employer account has unlocked, through
     * an application or straight from the Worker Database.
     *
     * @return list<int>
     */
    public function unlockedWorkerIds(): array
    {
        $fromApplications = JobApplication::where('contact_unlocked', true)
            ->whereHas('job', fn ($q) => $q->where('employer_id', $this->account->id))
            ->pluck('worker_id');

        $fromDirectory = WorkerContactUnlock::where('employer_id', $this->account->id)->pluck('worker_id');

        return $fromApplications->merge($fromDirectory)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function hasUnlocked(int $workerId): bool
    {
        return WorkerContactUnlock::where('employer_id', $this->account->id)->where('worker_id', $workerId)->exists()
            || JobApplication::where('worker_id', $workerId)
                ->where('contact_unlocked', true)
                ->whereHas('job', fn ($q) => $q->where('employer_id', $this->account->id))
                ->exists();
    }

    /**
     * Whether the karigar's number shows to this employer right now. It needs
     * an unlock and a plan to show it through: Worker Database access for any
     * unlocked karigar, or the job plan for an unlocked applicant. An
     * applicant the employer shortlisted or hired stays visible without one.
     */
    public function contactVisible(int $workerId): bool
    {
        $applications = JobApplication::where('worker_id', $workerId)
            ->where('contact_unlocked', true)
            ->whereHas('job', fn ($q) => $q->where('employer_id', $this->account->id));

        if ((clone $applications)->kept()->exists()) {
            return true;
        }

        if ($this->subscription(Plan::TYPE_JOB) !== null && (clone $applications)->exists()) {
            return true;
        }

        return $this->account->contactDatabaseQuota() > 0 && $this->hasUnlocked($workerId);
    }

    /**
     * Unlock a karigar from the Worker Database. Returns false when the
     * employer is out of unlocks; a karigar already unlocked costs nothing.
     */
    public function unlockWorker(User $worker, User $by): bool
    {
        return $this->unlock($worker->id, $by, WorkerContactUnlock::SOURCE_DIRECTORY);
    }

    /**
     * Reveal an applicant's contact. Returns false when the employer is out
     * of unlocks; a karigar already unlocked (on another job, or from the
     * Worker Database) costs nothing.
     */
    public function unlockApplication(JobApplication $application, User $by): bool
    {
        if ($application->contact_unlocked) {
            return true;
        }

        if (! $this->unlock($application->worker_id, $by, WorkerContactUnlock::SOURCE_APPLICATION)) {
            return false;
        }

        $application->update(['contact_unlocked' => true]);

        return true;
    }

    /**
     * Charge for a karigar the first time they are unlocked, and record it
     * with the pool that paid. The record's date places it in that pool's
     * billing cycle.
     */
    private function unlock(int $workerId, User $by, string $source): bool
    {
        if ($this->hasUnlocked($workerId)) {
            return true;
        }

        $pool = $this->charge($source);

        if ($pool === null) {
            return false;
        }

        WorkerContactUnlock::firstOrCreate(
            ['employer_id' => $this->account->id, 'worker_id' => $workerId],
            ['source' => $source, 'pool' => $pool, 'unlocked_by' => $by->id],
        );
        $this->pools = null;

        return true;
    }

    /**
     * Pick the plan pool that pays for one unlock. Null when every plan is
     * out of unlocks (or the account holds none).
     */
    private function charge(string $source): ?string
    {
        $order = $source === WorkerContactUnlock::SOURCE_DIRECTORY
            ? [WorkerContactUnlock::POOL_DATABASE, WorkerContactUnlock::POOL_JOB]
            : [WorkerContactUnlock::POOL_JOB, WorkerContactUnlock::POOL_DATABASE];

        $pools = $this->pools();

        foreach ($order as $pool) {
            if (isset($pools[$pool]) && ($pools[$pool]['remaining'] === null || $pools[$pool]['remaining'] > 0)) {
                return $pool;
            }
        }

        return null;
    }

    public function isUnmetered(): bool
    {
        return $this->pools() !== [] && $this->planRemaining() === null;
    }

    public function canUnlock(): bool
    {
        return $this->isUnmetered() || $this->planRemaining() > 0;
    }

    /**
     * The plan allowances, for the screens that count unlocks.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $job = $this->subscription(Plan::TYPE_JOB);
        $database = $this->subscription(Plan::TYPE_DATABASE);
        $main = $job ?? $database;
        $pools = $this->pools();

        return [
            'unmetered' => $this->isUnmetered(),
            'plan_limit' => $this->planLimit(),
            // Unlocks left across the plans; null when a plan does not meter them.
            'plan_remaining' => $this->planRemaining(),
            'unlocks_used' => $this->unlocksUsed(),
            'unlocks_reset_at' => $main?->ends_at?->toIso8601String(),
            'plan' => $job?->plan->name,
            // The database plan's own allowance, when the account holds one.
            'database_plan' => $database ? [
                'name' => $database->plan->name,
                'limit' => $pools[WorkerContactUnlock::POOL_DATABASE]['limit'],
                'used' => $pools[WorkerContactUnlock::POOL_DATABASE]['used'],
                'remaining' => $pools[WorkerContactUnlock::POOL_DATABASE]['remaining'],
                'renews_at' => $database->ends_at?->toIso8601String(),
            ] : null,
            'directory_quota' => $this->account->contactDatabaseQuota(),
        ];
    }

    /**
     * The "Worker Database" card on the employer home, in the app and on the
     * web: how many karigar contacts the account may browse, how many of
     * them it can still unlock, and the button that sells a database plan.
     * The lines are ready to show as they are.
     *
     * @return array<string, mixed>
     */
    public function database(): array
    {
        $contacts = $this->account->contactDatabaseQuota();
        $pools = $this->pools();

        // The database plan when the account holds one, else the job plan
        // that opens the database alongside its job posts.
        $pool = $pools[WorkerContactUnlock::POOL_DATABASE] ?? $pools[WorkerContactUnlock::POOL_JOB] ?? null;
        $subscription = isset($pools[WorkerContactUnlock::POOL_DATABASE])
            ? $this->subscription(Plan::TYPE_DATABASE)
            : $this->subscription(Plan::TYPE_JOB);

        if ($contacts <= 0 || $pool === null) {
            return [
                'active' => false,
                'plan' => null,
                'plan_type' => null,
                'contacts' => 0,
                'unlock_limit' => 0,
                'unlocks_used' => 0,
                'unlocks_remaining' => 0,
                'renews_at' => null,
                'title' => __('Worker Database'),
                'subtitle' => __('Buy a database plan to see karigar numbers'),
                'cta' => __('Buy Database'),
            ];
        }

        $remaining = $pool['remaining'];

        return [
            'active' => true,
            'plan' => $pool['plan'],
            // database: a database plan; job: the job plan includes the database.
            'plan_type' => isset($pools[WorkerContactUnlock::POOL_DATABASE]) ? Plan::TYPE_DATABASE : Plan::TYPE_JOB,
            'contacts' => $contacts,
            'unlock_limit' => $pool['limit'],
            'unlocks_used' => $pool['used'],
            // null: the plan does not meter unlocks.
            'unlocks_remaining' => $remaining,
            'renews_at' => $subscription?->ends_at?->toIso8601String(),
            'title' => __(':count karigar contacts', ['count' => number_format($contacts)]),
            'subtitle' => $remaining === null
                ? __('Unlimited unlocks').' · '.$pool['plan']
                : __(':left of :limit unlocks left', ['left' => number_format($remaining), 'limit' => number_format($pool['limit'])]).' · '.$pool['plan'],
            'cta' => $remaining === 0 ? __('Upgrade') : __('View plans'),
        ];
    }
}
