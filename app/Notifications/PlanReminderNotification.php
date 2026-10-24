<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\TemplatedMailChannel;
use App\Notifications\Messages\FcmMessage;
use App\Notifications\Messages\TemplatedMailMessage;
use App\Services\Billing\PlanReminders;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells an employer where their plan stands: it renews in a few days (and
 * what that charges), it ends in a few days, a renewal payment failed, or it
 * has ended and what stopped with it. Sent by {@see PlanReminders}, once per
 * billing cycle each.
 */
class PlanReminderNotification extends Notification
{
    use Queueable;

    public const RENEWING = 'renewing';

    public const ENDING = 'ending';

    public const PAYMENT_FAILED = 'payment_failed';

    public const ENDED = 'ended';

    /**
     * @param  string  $state  renewing | ending | payment_failed | ended
     * @param  int  $liveJobs  the employer's live jobs, which a lapsed job plan pauses
     */
    public function __construct(public Subscription $subscription, public string $state, public int $liveJobs = 0) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class, TemplatedMailChannel::class];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create($this->title(), $this->line(), [
            'type' => 'plan.reminder',
            'state' => $this->state,
            'subscription_id' => (string) $this->subscription->id,
            'plan_type' => $this->subscription->plan->type,
            'url' => $this->url(),
        ]);
    }

    public function toTemplatedMail(object $notifiable): TemplatedMailMessage
    {
        return TemplatedMailMessage::create('plan_'.$this->state, [
            'employer_name' => $notifiable->name ?? '',
            'plan_name' => $this->subscription->plan->name,
            'end_date' => $this->endDate(),
            'amount' => $this->amount(),
            'effect' => $this->effect(),
            'action_url' => url($this->url()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'plan.reminder',
            'state' => $this->state,
            'subscription_id' => $this->subscription->id,
            'plan_type' => $this->subscription->plan->type,
            'title' => $this->title(),
            'message' => $this->line(),
            'url' => $this->url(),
        ];
    }

    /**
     * The plans page, at the database plans for a database plan.
     */
    private function url(): string
    {
        return $this->subscription->plan->isDatabase() ? '/subscription#database' : '/subscription';
    }

    private function title(): string
    {
        return match ($this->state) {
            self::RENEWING => __('Your plan renews on :date', ['date' => $this->endDate()]),
            self::ENDING => __('Your plan ends on :date', ['date' => $this->endDate()]),
            self::PAYMENT_FAILED => __('Plan renewal payment failed'),
            default => __('Your plan has ended'),
        };
    }

    private function line(): string
    {
        $plan = $this->subscription->plan->name;

        return match ($this->state) {
            self::RENEWING => __('Your :plan plan renews by itself on :date and :amount will be charged.', ['plan' => $plan, 'date' => $this->endDate(), 'amount' => $this->amount()]),
            self::ENDING => __('Your :plan plan ends on :date. :effect Renew to keep going.', ['plan' => $plan, 'date' => $this->endDate(), 'effect' => $this->effect()]),
            self::PAYMENT_FAILED => __('We could not charge :amount to renew your :plan plan. Check your card or UPI, or buy the plan again. :effect', ['plan' => $plan, 'amount' => $this->amount(), 'effect' => $this->effect()]),
            default => __('Your :plan plan has ended. :effect Renew to start again.', ['plan' => $plan, 'effect' => $this->effect()]),
        };
    }

    /**
     * What stops with the plan: posting and the live jobs for a job plan,
     * the Worker Database for a database plan.
     */
    private function effect(): string
    {
        $ended = in_array($this->state, [self::ENDED, self::PAYMENT_FAILED], true);

        if ($this->subscription->plan->isDatabase()) {
            return $ended
                ? __('Worker Database access has stopped.')
                : __('Worker Database access stops then.');
        }

        if ($this->liveJobs === 0) {
            return $ended
                ? __('You cannot post jobs until you renew.')
                : __('You will not be able to post jobs after that.');
        }

        return $ended
            ? trans_choice('{1} Your live job is hidden from karigars.|[2,*] Your :count live jobs are hidden from karigars.', $this->liveJobs, ['count' => $this->liveJobs])
            : trans_choice('{1} Your live job will be hidden from karigars then.|[2,*] Your :count live jobs will be hidden from karigars then.', $this->liveJobs, ['count' => $this->liveJobs]);
    }

    /**
     * The day the plan ends, or ended: a plan cancelled early ended today,
     * not on the date it would have run to.
     */
    private function endDate(): string
    {
        $end = $this->subscription->ends_at;

        if ($end !== null && $this->state === self::ENDED && $end->isFuture()) {
            $end = now();
        }

        return $end?->copy()->timezone(config('app.display_timezone'))->format('j M Y') ?? '—';
    }

    private function amount(): string
    {
        return '₹'.number_format($this->subscription->renewalAmount(), 2);
    }
}
