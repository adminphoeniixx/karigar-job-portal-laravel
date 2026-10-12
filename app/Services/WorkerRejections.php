<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkerRejection;

/**
 * The karigars an employer account turned down in the Worker Database.
 * Rejecting costs nothing: no unlock is spent, the number stays hidden, and
 * the karigar simply leaves the searches, so the next one moves up into the
 * plan's reach. Team members reject for the account they belong to.
 */
class WorkerRejections
{
    /** @var list<int>|null */
    private ?array $ids = null;

    private function __construct(private User $account) {}

    public static function for(User $user): self
    {
        return new self($user->employerAccount());
    }

    /**
     * User ids of the rejected karigars.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return $this->ids ??= WorkerRejection::where('employer_id', $this->account->id)
            ->pluck('worker_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function has(int $workerId): bool
    {
        return in_array($workerId, $this->ids(), true);
    }

    public function count(): int
    {
        return count($this->ids());
    }

    public function reject(User $worker, User $by): void
    {
        WorkerRejection::firstOrCreate(
            ['employer_id' => $this->account->id, 'worker_id' => $worker->id],
            ['rejected_by' => $by->id],
        );

        $this->ids = null;
    }

    public function restore(User $worker): void
    {
        WorkerRejection::where('employer_id', $this->account->id)->where('worker_id', $worker->id)->delete();

        $this->ids = null;
    }

    /**
     * A Typesense `filter_by` clause keeping rejected karigars out (or, for
     * the "Rejected" tab, only them). Null when there is nothing to add; for
     * the tab with nothing rejected the caller shows an empty list.
     */
    public function searchFilter(bool $onlyRejected): ?string
    {
        if ($this->ids() === []) {
            return null;
        }

        return 'user_id:'.($onlyRejected ? '=' : '!=').'['.implode(',', $this->ids()).']';
    }
}
