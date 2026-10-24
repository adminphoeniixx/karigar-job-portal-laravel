<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\PlanReminderNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The notices an employer gets about their plan, each once per billing cycle
 * (marked with the `ends_at` it was about, so a renewal starts them afresh):
 *
 *  - a few days before the end: "renews on … and ₹… will be charged", or
 *    "ends on …" when Razorpay will not charge again;
 *  - a renewal payment that failed (Razorpay's `subscription.pending`);
 *  - the plan has ended, and what stopped with it.
 *
 * `plans:remind` sends the first and the last every hour; the Razorpay
 * webhook sends the payment failure, and the end of a halted or cancelled
 * plan straight away.
 */
class PlanReminders
{
    /** How many days before the end the reminder goes. */
    public const DAYS_BEFORE = 3;

    /**
     * A plan that renews by itself is charged around its end and the webhook
     * can arrive a little after; it is not called ended before this.
     */
    public const RENEWAL_GRACE_HOURS = 6;

    /** Only plans that stopped this recently are announced, so old lapses are never mailed. */
    public const LOOKBACK_DAYS = 3;

    /**
     * Plans running out within {@see DAYS_BEFORE} days, not yet reminded.
     *
     * @return Collection<int, Subscription>
     */
    public function upcoming(): Collection
    {
        return Subscription::with(['plan', 'employer'])
            ->entitled()
            ->whereBetween('ends_at', [now(), now()->addDays(self::DAYS_BEFORE)])
            ->where(fn (Builder $q) => $q->whereNull('reminded_for')->orWhereColumn('reminded_for', '<>', 'ends_at'))
            ->get();
    }

    public function remind(Subscription $subscription): string
    {
        $state = $subscription->willRenew() ? PlanReminderNotification::RENEWING : PlanReminderNotification::ENDING;

        $subscription->employer->notify(new PlanReminderNotification($subscription, $state, $this->liveJobs($subscription)));
        $subscription->update(['reminded_for' => $subscription->ends_at]);

        return $state;
    }

    /**
     * Plans that stopped lately and have not been announced: past their end
     * with no renewal, or halted, cancelled or completed at Razorpay.
     *
     * @return Collection<int, Subscription>
     */
    public function lapses(): Collection
    {
        $since = now()->subDays(self::LOOKBACK_DAYS);
        $stopped = [SubscriptionStatus::Halted, SubscriptionStatus::Cancelled, SubscriptionStatus::Completed, SubscriptionStatus::Expired];

        return Subscription::with(['plan', 'employer'])
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->where(fn (Builder $q) => $q->whereNull('lapse_notified_for')->orWhereColumn('lapse_notified_for', '<>', 'ends_at'))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q
                    ->whereIn('status', array_map(fn ($s) => $s->value, SubscriptionStatus::entitled()))
                    ->whereBetween('ends_at', [$since, now()]))
                ->orWhere(fn (Builder $q) => $q
                    ->whereIn('status', array_map(fn ($s) => $s->value, $stopped))
                    ->where('updated_at', '>=', $since)))
            ->get()
            ->reject(fn (Subscription $s) => $this->awaitingRenewal($s))
            ->values();
    }

    /**
     * Tell the employer the plan has ended. Not when it still runs, or when
     * another plan of the same kind took over (a switch, or a fresh purchase).
     */
    public function lapsed(Subscription $subscription): bool
    {
        if ($subscription->isActive() || $subscription->lapse_notified_for?->equalTo($subscription->ends_at)) {
            return false;
        }

        $replaced = $subscription->employer->activeSubscription($subscription->plan->type) !== null;

        if (! $replaced) {
            $subscription->employer->notify(new PlanReminderNotification($subscription, PlanReminderNotification::ENDED, $this->liveJobs($subscription)));
        }

        $subscription->update(['lapse_notified_for' => $subscription->ends_at]);

        return ! $replaced;
    }

    /**
     * Razorpay could not charge the renewal and will try again for a few
     * days; the employer can pay in the meantime or fix their card.
     */
    public function paymentFailed(Subscription $subscription): void
    {
        if ($subscription->payment_failed_for !== null && $subscription->payment_failed_for->equalTo($subscription->ends_at)) {
            return;
        }

        $subscription->employer->notify(new PlanReminderNotification($subscription, PlanReminderNotification::PAYMENT_FAILED, $this->liveJobs($subscription)));
        $subscription->update(['payment_failed_for' => $subscription->ends_at]);
    }

    /**
     * Just past its end, the charge for a plan that renews by itself may
     * still be on its way.
     */
    private function awaitingRenewal(Subscription $subscription): bool
    {
        return $subscription->status->isEntitled()
            && $subscription->ends_at->isAfter(now()->subHours(self::RENEWAL_GRACE_HOURS))
            && $subscription->willRenew();
    }

    /**
     * Live jobs a lapsed job plan hides; a database plan hides none.
     */
    private function liveJobs(Subscription $subscription): int
    {
        return $subscription->plan->isDatabase() ? 0 : $subscription->employer->jobListings()->active()->count();
    }
}
