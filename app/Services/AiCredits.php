<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\ScreeningCallStatus;
use App\Models\AiCreditEntry;
use App\Models\AiCreditPack;
use App\Models\AiCreditPurchase;
use App\Models\JobListing;
use App\Models\ScreeningCall;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AiCreditsLow;
use App\Services\Screening\ScreeningService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * An employer's prepaid AI call credits: the balance, the ledger behind it,
 * and the rules for spending it.
 *
 * - Every change to a balance is one AiCreditEntry, written under a lock on
 *   the account row, so users.ai_credits always equals the ledger's sum.
 * - A call holds enough credits for its longest possible run while it is on
 *   the line ({@see holdPerCall()}), so two calls at once cannot spend money
 *   that is not there. When it ends it is charged per started minute, and
 *   only if the worker actually talked (status completed). Missed, busy and
 *   failed calls cost nothing.
 *
 * The rate, the minimum to switch AI calls on for a job, the low-balance
 * alert and the welcome gift are all in Admin → AI Credits.
 */
class AiCredits
{
    public const ENABLED_KEY = 'ai_credits_enabled';

    public const PER_MINUTE_KEY = 'ai_credits_per_minute';

    public const MIN_BALANCE_KEY = 'ai_credits_min_balance';

    public const LOW_ALERT_KEY = 'ai_credits_low_alert';

    public const WELCOME_KEY = 'ai_credits_welcome';

    public const DEFAULT_PER_MINUTE = 1;

    public const DEFAULT_MIN_BALANCE = 10;

    public const DEFAULT_LOW_ALERT = 20;

    /** Off until the admin sets a number. */
    public const DEFAULT_WELCOME = 0;

    /**
     * A call still "on the line" after this long lost its result callback;
     * it stops holding credits rather than freezing the balance forever.
     */
    public const STALE_HOLD_MINUTES = 30;

    private const LIVE = [ScreeningCallStatus::Queued, ScreeningCallStatus::Dialing, ScreeningCallStatus::InProgress];

    private function __construct(private readonly User $account) {}

    public static function for(User $user): self
    {
        return new self($user->employerAccount());
    }

    /**
     * Whether AI calls are paid for with credits. Off, every call is free and
     * nothing is checked: for the admin's own testing, or a launch offer.
     */
    public static function enabled(): bool
    {
        return Setting::bool(self::ENABLED_KEY, true);
    }

    public static function perMinute(): int
    {
        return max(1, Setting::int(self::PER_MINUTE_KEY, self::DEFAULT_PER_MINUTE));
    }

    public static function minBalance(): int
    {
        return max(0, Setting::int(self::MIN_BALANCE_KEY, self::DEFAULT_MIN_BALANCE));
    }

    public static function lowAlert(): int
    {
        return max(0, Setting::int(self::LOW_ALERT_KEY, self::DEFAULT_LOW_ALERT));
    }

    public static function welcome(): int
    {
        return max(0, Setting::int(self::WELCOME_KEY, self::DEFAULT_WELCOME));
    }

    /**
     * The longest a call can run, in started minutes. The agent cuts a call
     * off at SCREENING_MAX_CALL_SECONDS and then says goodbye, so a minute
     * of slack is added for that.
     */
    public static function maxCallMinutes(): int
    {
        return (int) ceil(((int) config('screening.max_call_seconds', 300) + 60) / 60);
    }

    /**
     * Credits a call holds while it is on the line: enough for its longest run.
     */
    public static function holdPerCall(): int
    {
        return self::maxCallMinutes() * self::perMinute();
    }

    /**
     * Credits for a call that ran this long: per started minute.
     */
    public static function costFor(int $seconds): int
    {
        return $seconds > 0 ? (int) ceil($seconds / 60) * self::perMinute() : 0;
    }

    public function account(): User
    {
        return $this->account;
    }

    public function balance(): int
    {
        return (int) User::whereKey($this->account->id)->value('ai_credits');
    }

    /**
     * Credits held by calls on the line right now.
     */
    public function held(?ScreeningCall $ignore = null): int
    {
        $live = ScreeningCall::whereIn('status', self::LIVE)
            ->where('updated_at', '>=', now()->subMinutes(self::STALE_HOLD_MINUTES))
            ->when($ignore !== null, fn ($q) => $q->whereKeyNot($ignore->id))
            ->whereHas('application.job', fn ($q) => $q->where('employer_id', $this->account->id))
            ->count();

        return $live * self::holdPerCall();
    }

    public function available(?ScreeningCall $ignore = null): int
    {
        return $this->balance() - $this->held($ignore);
    }

    /**
     * Enough credits to place one more call. Always true while credits are off.
     */
    public function canPlaceCall(?ScreeningCall $ignore = null): bool
    {
        return ! self::enabled() || $this->available($ignore) >= self::holdPerCall();
    }

    /**
     * Enough credits to switch AI calls on for a job.
     */
    public function meetsJobMinimum(): bool
    {
        return ! self::enabled() || $this->balance() >= max(self::minBalance(), self::holdPerCall());
    }

    /**
     * The number the job form asks for, for its "recharge first" message.
     */
    public static function jobMinimum(): int
    {
        return max(self::minBalance(), self::holdPerCall());
    }

    /**
     * Whether a job save may switch AI calls on. Null when it may; otherwise
     * the message telling the employer to recharge first.
     *
     * Only a job that is (or goes) live with the AI call switched on is
     * checked; a draft is always saved. A switch that was already on for a
     * live job is left alone, so an old job can still be edited; its calls
     * simply wait for credits. A new job that does not mention the switch
     * (older app builds) gets it switched off instead of being refused.
     *
     * @param  array<string, mixed>  $data  the validated job fields, changed in place
     */
    public static function jobGate(User $user, array &$data, ?JobListing $job = null): ?string
    {
        if (! self::enabled() || ! Setting::bool(ScreeningService::ENABLED_KEY, false)) {
            return null;
        }

        $status = $data['status'] ?? $job?->status?->value;
        $mentioned = array_key_exists('ai_call_enabled', $data);
        $wantsCall = $mentioned ? (bool) $data['ai_call_enabled'] : ($job === null || (bool) $job->ai_call_enabled);

        if ($status !== JobStatus::Active->value || ! $wantsCall) {
            return null;
        }

        $alreadyOn = $job !== null && $job->published_at !== null && (bool) $job->ai_call_enabled;

        if ($alreadyOn || self::for($user)->meetsJobMinimum()) {
            return null;
        }

        if (! $mentioned && $job === null) {
            $data['ai_call_enabled'] = false;

            return null;
        }

        return self::jobGateMessage();
    }

    public static function jobGateMessage(): string
    {
        return __('AI calling needs at least :min AI call credits. Recharge credits first, or switch AI calls off for this job.', ['min' => self::jobMinimum()]);
    }

    /**
     * Add or take credits, as one ledger row. Under a lock on the account, so
     * the balance and the row can never disagree.
     *
     * Returns null when the same purchase or call already has a row of this
     * type: a callback and a webhook both reporting one payment add it once.
     */
    public function record(
        string $type,
        int $credits,
        string $description,
        ?AiCreditPurchase $purchase = null,
        ?ScreeningCall $call = null,
        ?int $seconds = null,
        ?User $by = null,
    ): ?AiCreditEntry {
        $entry = DB::transaction(function () use ($type, $credits, $description, $purchase, $call, $seconds, $by) {
            $balance = (int) User::whereKey($this->account->id)->lockForUpdate()->value('ai_credits');

            $duplicate = ($purchase !== null && AiCreditEntry::where('ai_credit_purchase_id', $purchase->id)->where('type', $type)->exists())
                || ($call !== null && AiCreditEntry::where('screening_call_id', $call->id)->where('type', $type)->exists());

            if ($duplicate) {
                return null;
            }

            $after = $balance + $credits;

            User::whereKey($this->account->id)->update([
                'ai_credits' => $after,
                // Topped back up above the alert line: the next dip alerts again.
                ...($credits > 0 && $after >= self::lowAlert() ? ['ai_low_credit_alerted_at' => null] : []),
            ]);

            return AiCreditEntry::create([
                'employer_id' => $this->account->id,
                'type' => $type,
                'credits' => $credits,
                'balance_after' => $after,
                'description' => mb_substr($description, 0, 255),
                'ai_credit_purchase_id' => $purchase?->id,
                'screening_call_id' => $call?->id,
                'seconds' => $seconds,
                'created_by' => $by?->id,
            ]);
        });

        $this->account->ai_credits = $this->balance();

        return $entry;
    }

    /**
     * Credit a paid recharge: the pack's credits, then its bonus as a line of
     * its own so the ledger shows what was a gift.
     */
    public function creditPurchase(AiCreditPurchase $purchase): void
    {
        $this->record(
            AiCreditEntry::TYPE_RECHARGE,
            $purchase->credits,
            __('Recharge — :pack pack', ['pack' => $purchase->pack_name]),
            purchase: $purchase,
        );

        if ($purchase->bonus_credits > 0) {
            $this->record(
                AiCreditEntry::TYPE_BONUS,
                $purchase->bonus_credits,
                __('Bonus with the :pack pack', ['pack' => $purchase->pack_name]),
                purchase: $purchase,
            );
        }
    }

    /**
     * Charge a finished call for the minutes it ran. Only a call the worker
     * actually took is charged; the rest cost nothing.
     */
    public function chargeCall(ScreeningCall $call): ?AiCreditEntry
    {
        if (! self::enabled() || $call->status !== ScreeningCallStatus::Completed) {
            return null;
        }

        $seconds = (int) ($call->duration_seconds
            ?? ($call->started_at && $call->ended_at ? $call->started_at->diffInSeconds($call->ended_at) : 0));
        $cost = self::costFor($seconds);

        if ($cost === 0) {
            return null;
        }

        $call->loadMissing('worker', 'application.job');

        $entry = $this->record(
            AiCreditEntry::TYPE_CALL,
            -$cost,
            __('AI call — :worker · :job · :duration', [
                'worker' => $call->worker?->name ?? __('Applicant'),
                'job' => $call->application?->job?->title ?? '—',
                'duration' => self::durationLabel($seconds),
            ]),
            call: $call,
            seconds: $seconds,
        );

        if ($entry !== null) {
            $call->update(['credits_charged' => $cost]);
            $this->alertIfLow();
        }

        return $entry;
    }

    /**
     * The admin's welcome gift, once per account, the first time its wallet is
     * looked at. Lazy so that existing employers get it too, without a sweep.
     */
    public function grantWelcome(): void
    {
        $amount = self::welcome();

        if ($amount <= 0 || ! self::enabled() || $this->account->ai_welcome_credited_at !== null) {
            return;
        }

        // Claim it first: of two requests racing here, one wins the update.
        $claimed = User::whereKey($this->account->id)->whereNull('ai_welcome_credited_at')->update(['ai_welcome_credited_at' => now()]);
        $this->account->ai_welcome_credited_at = now();

        if ($claimed === 1) {
            $this->record(AiCreditEntry::TYPE_WELCOME, $amount, __('Welcome credits to try AI calls'));
        }
    }

    /**
     * Tell the employer once when the balance drops under the alert line.
     */
    public function alertIfLow(): void
    {
        $balance = $this->balance();

        if ($balance >= self::lowAlert() && $balance >= self::holdPerCall()) {
            return;
        }

        $claimed = User::whereKey($this->account->id)->whereNull('ai_low_credit_alerted_at')->update(['ai_low_credit_alerted_at' => now()]);

        if ($claimed === 1) {
            $this->account->notify(new AiCreditsLow($balance, empty: $balance < self::holdPerCall()));
        }
    }

    /**
     * A call could not be placed for want of credits. The employer hears about
     * it at most twice a day, however many calls were waiting.
     */
    public function alertOutOfCredits(): void
    {
        if (Cache::add('ai-credits-empty-alert:'.$this->account->id, true, now()->addHours(12))) {
            $this->account->notify(new AiCreditsLow($this->balance(), empty: true));
        }
    }

    /**
     * Everything both apps show at the top of the credits screen.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $this->grantWelcome();

        $balance = $this->balance();
        $held = $this->held();

        return [
            // Off: AI calls are free and nothing below applies.
            'enabled' => self::enabled(),
            'balance' => $balance,
            // Held by calls on the line; spendable = available.
            'held' => $held,
            'available' => $balance - $held,
            'per_minute' => self::perMinute(),
            // Roughly how many call minutes the balance buys.
            'minutes' => intdiv(max(0, $balance - $held), self::perMinute()),
            'min_for_job' => self::jobMinimum(),
            'can_enable_on_job' => $this->meetsJobMinimum(),
            'low' => self::enabled() && $balance < self::lowAlert(),
            'max_call_minutes' => self::maxCallMinutes(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function packs(): array
    {
        return AiCreditPack::forSale()->get()->map(fn (AiCreditPack $pack) => $pack->toCard())->values()->all();
    }

    /**
     * The "how credits work" lines both apps show under the packs.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        $rate = self::perMinute();

        return [
            trans_choice('{1} 1 credit = 1 minute of AI calling.|[2,*] :rate credits = 1 minute of AI calling.', $rate, ['rate' => $rate]),
            __('Charged per started minute: a 2 min 10 s call is 3 minutes.'),
            __('Calls that are not picked up, busy or fail are free.'),
            __('Credits never expire.'),
            __('Keep at least :min credits to switch AI calls on for a job.', ['min' => self::jobMinimum()]),
        ];
    }

    public static function durationLabel(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $minutes > 0 ? "{$minutes} min {$rest} s" : "{$rest} s";
    }
}
