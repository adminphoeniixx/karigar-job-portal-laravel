<?php

namespace App\Services;

use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\User;
use App\Models\WorkerContactUnlock;

/**
 * The employer's contact-credit wallet, as the app's "12 contact credits" card
 * shows it. Credits come from two places:
 *
 *  - the active plan's contact-unlock allowance (metered per plan; a limit of
 *    0 means the plan does not meter unlocks at all), and
 *  - purchased top-ups stored on the employer profile (`credit_balance`).
 *
 * Unlocks spend the plan allowance first and fall back to purchased credits.
 * Applicants and the Worker Database share that one pool, and it counts
 * karigars, not unlock clicks: a karigar already unlocked either way is free.
 * Boosts always spend purchased credits.
 */
class CreditWallet
{
    private User $account;

    private EmployerProfile $profile;

    public function __construct(User $user)
    {
        $this->account = $user->employerAccount();
        $this->profile = $this->account->employerProfile()->firstOrCreate([]);
    }

    public static function for(User $user): self
    {
        return new self($user);
    }

    /**
     * The plan's contact-unlock allowance; 0 means "not metered by the plan".
     */
    public function planLimit(): int
    {
        return $this->account->activeSubscription()?->plan->contactUnlockLimit() ?? 0;
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

    /**
     * Contacts this employer account has already unlocked.
     */
    public function unlocksUsed(): int
    {
        return count($this->unlockedWorkerIds());
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
     * Unlock a karigar from the Worker Database. Returns false when the
     * employer is out of unlocks; a karigar already unlocked costs nothing.
     */
    public function unlockWorker(User $worker, User $by): bool
    {
        if ($this->hasUnlocked($worker->id)) {
            return true;
        }

        if (! $this->canUnlock()) {
            return false;
        }

        $this->consumeUnlock();

        WorkerContactUnlock::firstOrCreate(
            ['employer_id' => $this->account->id, 'worker_id' => $worker->id],
            ['unlocked_by' => $by->id],
        );

        return true;
    }

    /**
     * Unlocks left on the plan, or null when the plan does not meter them.
     */
    public function planRemaining(): ?int
    {
        $limit = $this->planLimit();

        return $limit > 0 ? max($limit - $this->unlocksUsed(), 0) : null;
    }

    /**
     * Purchased (top-up) credits.
     */
    public function purchased(): int
    {
        return (int) $this->profile->credit_balance;
    }

    public function isUnmetered(): bool
    {
        return $this->planRemaining() === null;
    }

    /**
     * Spendable credits right now (purchased + plan allowance left).
     */
    public function balance(): int
    {
        return $this->purchased() + ($this->planRemaining() ?? 0);
    }

    public function canUnlock(): bool
    {
        return $this->isUnmetered() || $this->planRemaining() > 0 || $this->purchased() > 0;
    }

    /**
     * Charge one contact unlock: plan allowance first, then a purchased credit.
     */
    public function consumeUnlock(): void
    {
        if ($this->isUnmetered() || $this->planRemaining() > 0) {
            return; // Covered by the plan; nothing to deduct.
        }

        $this->spend(1);
    }

    public function canSpend(int $credits): bool
    {
        return $this->purchased() >= $credits;
    }

    /**
     * Deduct purchased credits (boosts). Returns false when short.
     */
    public function spend(int $credits): bool
    {
        if (! $this->canSpend($credits)) {
            return false;
        }

        $this->profile->decrement('credit_balance', $credits);
        $this->profile->refresh();

        return true;
    }

    /**
     * Add purchased credits (paid top-up or admin grant).
     */
    public function add(int $credits): void
    {
        $this->profile->increment('credit_balance', $credits);
        $this->profile->refresh();
    }

    /**
     * Wallet summary for the home card / Plans screen.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $subscription = $this->account->activeSubscription();

        return [
            'balance' => $this->balance(),
            'unmetered' => $this->isUnmetered(),
            'purchased' => $this->purchased(),
            'plan_limit' => $this->planLimit(),
            'plan_remaining' => $this->planRemaining(),
            'unlocks_used' => $this->unlocksUsed(),
            'plan' => $subscription?->plan->name,
            'plan_label' => $subscription
                ? $subscription->plan->name.' · '.__('renews :date', ['date' => $subscription->ends_at?->format('d M Y') ?? '—'])
                : __('Free plan · unlock karigar numbers'),
            'directory_quota' => $this->account->contactDatabaseQuota(),
        ];
    }
}
