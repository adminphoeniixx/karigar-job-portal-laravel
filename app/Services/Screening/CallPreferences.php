<?php

namespace App\Services\Screening;

use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\ScreeningCall;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * An employer's own rules for the AI calls their applicants get: when the
 * call goes out after an application or a shortlist, inside which hours and
 * on which days, who qualifies, how many a job may make in a day, and whether
 * a booked interview gets a reminder call on the morning.
 *
 * Kept as JSON on the employer profile (`ai_call_settings`). Everything has a
 * default that reproduces what the platform did before the settings existed,
 * so an employer who never opens the page sees no change: calls on shortlist,
 * at once, within the admin's calling hours, every day, no cap.
 *
 * The admin's window (config screening.window, the TRAI daytime limit) is the
 * outer bound: an employer can narrow their hours inside it, never widen them.
 */
final class CallPreferences
{
    /** Call after the employer (or the AI) shortlists, or as soon as someone applies. */
    public const TRIGGERS = ['shortlist', 'apply'];

    /** Who gets an automatic call: everyone, only AI scores at or above min_score, or nobody the employer did not shortlist by hand. */
    public const WHO = ['all', 'score', 'manual'];

    /** Minutes between the trigger and the call. 1440 means the next day's opening hour, not 24 hours later. */
    public const DELAYS = [0, 30, 60, 240, 1440];

    public const NEXT_DAY = 1440;

    public const DEFAULT_MIN_SCORE = 60;

    public function __construct(
        public readonly string $trigger = 'shortlist',
        public readonly int $delayMinutes = 0,
        public readonly ?string $windowStart = null,
        public readonly ?string $windowEnd = null,
        /** @var list<int> ISO weekdays, 1 = Monday … 7 = Sunday */
        public readonly array $days = [1, 2, 3, 4, 5, 6, 7],
        public readonly string $who = 'all',
        public readonly int $minScore = self::DEFAULT_MIN_SCORE,
        /** 0 = no cap */
        public readonly int $dailyLimit = 0,
        public readonly bool $reminderCall = true,
    ) {}

    public static function defaults(): self
    {
        return new self;
    }

    /**
     * The rules of the account this user belongs to (team members share the
     * owner's). No profile, or nothing saved yet, means the defaults.
     */
    public static function for(?User $user): self
    {
        $account = $user?->employerAccount();

        if ($account === null) {
            return self::defaults();
        }

        // Read fresh rather than off a loaded relation: a save a moment ago
        // (the settings form, a test) must be what the next call obeys.
        $saved = EmployerProfile::where('user_id', $account->id)->value('ai_call_settings');
        $saved = is_string($saved) ? json_decode($saved, true) : $saved;

        return is_array($saved) ? self::fromArray($saved) : self::defaults();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $defaults = self::defaults();
        $days = array_values(array_unique(array_map('intval', (array) ($data['days'] ?? $defaults->days))));
        $days = array_values(array_filter($days, fn (int $d) => $d >= 1 && $d <= 7));
        sort($days);

        return new self(
            trigger: in_array($data['trigger'] ?? null, self::TRIGGERS, true) ? $data['trigger'] : $defaults->trigger,
            delayMinutes: in_array((int) ($data['delay_minutes'] ?? 0), self::DELAYS, true) ? (int) ($data['delay_minutes'] ?? 0) : 0,
            windowStart: self::time($data['window_start'] ?? null),
            windowEnd: self::time($data['window_end'] ?? null),
            days: $days !== [] ? $days : $defaults->days,
            who: in_array($data['who'] ?? null, self::WHO, true) ? $data['who'] : $defaults->who,
            minScore: max(0, min(100, (int) ($data['min_score'] ?? self::DEFAULT_MIN_SCORE))),
            dailyLimit: max(0, min(500, (int) ($data['daily_limit'] ?? 0))),
            reminderCall: (bool) ($data['reminder_call'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trigger' => $this->trigger,
            'delay_minutes' => $this->delayMinutes,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'days' => $this->days,
            'who' => $this->who,
            'min_score' => $this->minScore,
            'daily_limit' => $this->dailyLimit,
            'reminder_call' => $this->reminderCall,
        ];
    }

    public function save(User $user): void
    {
        $profile = $user->employerAccount()->employerProfile()->firstOrCreate([]);
        $profile->update(['ai_call_settings' => $this->toArray()]);
    }

    /**
     * Validation for the settings form, web and API alike.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        [$start, $end] = self::adminWindow();

        return [
            'trigger' => ['required', Rule::in(self::TRIGGERS)],
            'delay_minutes' => ['required', 'integer', Rule::in(self::DELAYS)],
            'window_start' => ['nullable', 'date_format:H:i', 'after_or_equal:'.$start, 'before:'.$end],
            'window_end' => ['nullable', 'date_format:H:i', 'after:window_start', 'before_or_equal:'.$end],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],
            'who' => ['required', Rule::in(self::WHO)],
            'min_score' => ['required', 'integer', 'between:0,100'],
            'daily_limit' => ['required', 'integer', 'between:0,500'],
            'reminder_call' => ['required', 'boolean'],
        ];
    }

    /**
     * What the settings screen offers, with labels, so web and app show the
     * same choices.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        [$start, $end] = self::adminWindow();

        return [
            'triggers' => [
                ['value' => 'shortlist', 'label' => __('After shortlisting'), 'description' => __('When you shortlist an applicant, or the AI shortlist does.')],
                ['value' => 'apply', 'label' => __('As soon as they apply'), 'description' => __('Every new applicant is called, before anyone looks at them.')],
            ],
            'delays' => [
                ['value' => 0, 'label' => __('Right away')],
                ['value' => 30, 'label' => __('After 30 minutes')],
                ['value' => 60, 'label' => __('After 1 hour')],
                ['value' => 240, 'label' => __('After 4 hours')],
                ['value' => self::NEXT_DAY, 'label' => __('Next day, when calling hours open')],
            ],
            'who' => [
                ['value' => 'all', 'label' => __('Everyone'), 'description' => __('Every applicant the trigger covers.')],
                ['value' => 'score', 'label' => __('Only strong AI matches'), 'description' => __('Only applicants whose AI match score is at or above the score you set.')],
                ['value' => 'manual', 'label' => __('Only the ones I shortlist myself'), 'description' => __('No automatic calls. Applicants are called when you shortlist them or press Call.')],
            ],
            'days' => [
                ['value' => 1, 'label' => __('Mon')], ['value' => 2, 'label' => __('Tue')], ['value' => 3, 'label' => __('Wed')],
                ['value' => 4, 'label' => __('Thu')], ['value' => 5, 'label' => __('Fri')], ['value' => 6, 'label' => __('Sat')],
                ['value' => 7, 'label' => __('Sun')],
            ],
            // The admin's bounds, so the form can clamp its time pickers.
            'window' => ['start' => $start, 'end' => $end, 'timezone' => self::timezone()],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Time
    |--------------------------------------------------------------------------
    */

    /**
     * The hours calls may go out: the employer's own, clipped to the admin's.
     *
     * @return array{0: string, 1: string} 'HH:MM' start and end
     */
    public function window(): array
    {
        [$adminStart, $adminEnd] = self::adminWindow();

        $start = $this->windowStart !== null && $this->windowStart > $adminStart ? $this->windowStart : $adminStart;
        $end = $this->windowEnd !== null && $this->windowEnd < $adminEnd ? $this->windowEnd : $adminEnd;

        // A window that closed before it opened is a mistake; the admin's wins.
        return $start < $end ? [$start, $end] : [$adminStart, $adminEnd];
    }

    /**
     * The first moment at or after $from when a call may go out: inside the
     * hours, on a working day. $from itself when it already qualifies.
     */
    public function nextCallableTime(?CarbonImmutable $from = null): CarbonImmutable
    {
        $tz = self::timezone();
        $from = ($from ?? CarbonImmutable::now())->setTimezone($tz);
        [$open, $close] = $this->window();

        // Fourteen days covers any pattern of off days; a settings row with no
        // working day at all is already rejected by fromArray().
        for ($i = 0; $i <= 14; $i++) {
            $day = $from->addDays($i);

            if (! in_array($day->isoWeekday(), $this->days, true)) {
                continue;
            }

            $start = $day->setTimeFromTimeString($open);
            $end = $day->setTimeFromTimeString($close);

            if ($i > 0 || $from->lt($start)) {
                return $start;
            }

            if ($from->lte($end)) {
                return $from;
            }
        }

        return $from->addDay()->setTimeFromTimeString($open);
    }

    /**
     * When an automatic call set off at $from should ring: the delay, then
     * the next callable moment after it.
     */
    public function callAt(?CarbonImmutable $from = null): CarbonImmutable
    {
        $from = ($from ?? CarbonImmutable::now())->setTimezone(self::timezone());

        $due = $this->delayMinutes === self::NEXT_DAY
            ? $from->addDay()->setTimeFromTimeString($this->window()[0])
            : $from->addMinutes($this->delayMinutes);

        return $this->nextCallableTime($due);
    }

    /**
     * The soonest callable moment on a day whose cap this job has not hit;
     * $at itself when there is no cap or room under it. Looks a week ahead at
     * most, then gives up and returns the last day tried rather than never
     * calling.
     */
    public function withinDailyLimit(JobListing $job, CarbonImmutable $at): CarbonImmutable
    {
        if ($this->dailyLimit <= 0) {
            return $at;
        }

        for ($i = 0; $i < 7; $i++) {
            if (! $this->dailyLimitReached($job, $at)) {
                return $at;
            }

            $at = $this->nextCallableTime($at->setTimezone(self::timezone())->addDay()->startOfDay());
        }

        return $at;
    }

    /**
     * Whether this job already has its day's worth of calls booked or made on
     * the day $at falls on. Cancelled calls do not count; a booked one does,
     * so a burst of applicants cannot all be booked for the same morning.
     */
    public function dailyLimitReached(JobListing $job, CarbonImmutable $at): bool
    {
        if ($this->dailyLimit <= 0) {
            return false;
        }

        $day = $at->setTimezone(self::timezone())->startOfDay();
        $start = $day->setTimezone(config('app.timezone'));
        $end = $day->addDay()->setTimezone(config('app.timezone'));

        $count = ScreeningCall::query()
            ->whereHas('application', fn ($q) => $q->where('job_listing_id', $job->id))
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('scheduled_at', [$start, $end])
                    ->orWhere(fn ($q) => $q->whereNull('scheduled_at')->whereBetween('created_at', [$start, $end]));
            })
            ->count();

        return $count >= $this->dailyLimit;
    }

    /**
     * Whether an automatic call (apply, AI shortlist) may go to this applicant.
     * A shortlist by hand always may: the employer chose them.
     */
    public function allowsAutoCall(JobApplication $application, string $source): bool
    {
        if ($source === 'shortlist' || $source === 'manual') {
            return true;
        }

        return match ($this->who) {
            'manual' => false,
            'score' => $application->ai_score !== null && (int) $application->ai_score >= $this->minScore,
            default => true,
        };
    }

    public function callsOnApply(): bool
    {
        return $this->trigger === 'apply';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function adminWindow(): array
    {
        return [
            self::time(config('screening.window.start')) ?? '10:00',
            self::time(config('screening.window.end')) ?? '19:00',
        ];
    }

    public static function timezone(): string
    {
        return (string) config('screening.window.timezone', 'Asia/Kolkata');
    }

    /** 'H:i' or null; '9:30' and '09:30:00' both normalise to '09:30'. */
    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $m) !== 1) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
