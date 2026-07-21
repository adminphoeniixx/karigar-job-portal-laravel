<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

/**
 * Decides whether an employer account may post another job. Shared by the web
 * and API controllers so both surfaces enforce the same rules:
 *
 *  - With an active subscription: the plan's job_post_limit applies (0 = unlimited).
 *  - Without a subscription: the account may post one lifetime free job, but only
 *    while the "first post free" promo is enabled by an admin. After that, a
 *    subscription is required.
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

            if ($limit > 0 && $account->jobListings()->count() >= $limit) {
                return self::deny(__('You have reached your plan\'s job posting limit.'));
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
