<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

/**
 * Decides whether an employer account may post another job. Shared by the web
 * and API controllers so both surfaces enforce the same rules:
 *
 *  - With an active subscription: the plan's job_post_limit applies per billing
 *    cycle (0 = unlimited). Only jobs that went live in the current cycle count;
 *    drafts never do, and a job closed and reopened counts once.
 *  - Without a subscription: the account may post one lifetime free job, but only
 *    while the "first post free" promo is enabled by an admin. After that, a
 *    subscription is required.
 *
 * It is asked when a job goes live, not when it is saved: a draft costs
 * nothing, and publishing it is the moment the quota is spent.
 */
class JobPostingGate
{
    /**
     * @return array{allowed: bool, message: ?string, consumesFreePost: bool}
     */
    public static function evaluate(User $account): array
    {
        $subscription = $account->activeSubscription();

        if ($subscription) {
            $limit = $subscription->plan->jobPostLimit();

            if ($limit > 0 && self::postedThisCycle($account) >= $limit) {
                return self::deny(__('You have used all :limit job posts in your plan for this billing period. Save it as a draft, or upgrade your plan.', ['limit' => $limit]));
            }

            return self::allow(false);
        }

        // No active subscription — the free post is the only way in.
        if (Setting::bool('first_post_free_enabled', true) && self::freePostAvailable($account)) {
            return self::allow(true);
        }

        return self::deny(__('Subscribe to a plan to post jobs.'));
    }

    /**
     * Jobs this account has put live in the current billing cycle.
     */
    public static function postedThisCycle(User $account): int
    {
        $subscription = $account->activeSubscription();

        if (! $subscription) {
            return 0;
        }

        return $account->jobListings()
            ->where('published_at', '>=', $subscription->currentCycleStart())
            ->count();
    }

    /**
     * Posting quota for the plan screens, or null without a subscription.
     *
     * @return array{used: int, limit: int, unlimited: bool, resets_at: ?string}|null
     */
    public static function usage(User $account): ?array
    {
        $subscription = $account->activeSubscription();

        if (! $subscription) {
            return null;
        }

        $limit = $subscription->plan->jobPostLimit();

        return [
            'used' => self::postedThisCycle($account),
            'limit' => $limit,
            'unlimited' => $limit === 0,
            'resets_at' => $subscription->ends_at?->toIso8601String(),
        ];
    }

    /**
     * Whether this account still has its one lifetime free post.
     */
    public static function freePostAvailable(User $account): bool
    {
        return $account->employerProfile !== null
            && $account->employerProfile->free_post_used_at === null;
    }

    /**
     * Mark the account's free post as consumed. Call once, after the job that
     * used the free post is created.
     */
    public static function consumeFreePost(User $account): void
    {
        $account->employerProfile?->update(['free_post_used_at' => now()]);
    }

    /**
     * @return array{allowed: true, message: null, consumesFreePost: bool}
     */
    private static function allow(bool $consumesFreePost): array
    {
        return ['allowed' => true, 'message' => null, 'consumesFreePost' => $consumesFreePost];
    }

    /**
     * @return array{allowed: false, message: string, consumesFreePost: false}
     */
    private static function deny(string $message): array
    {
        return ['allowed' => false, 'message' => $message, 'consumesFreePost' => false];
    }
}
