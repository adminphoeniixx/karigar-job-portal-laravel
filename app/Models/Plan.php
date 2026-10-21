<?php

namespace App\Models;

use App\Services\Billing\Gst;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $type
 * @property string $price
 * @property string $currency
 * @property string|null $tagline
 * @property string|null $mrp
 * @property string $interval
 * @property int|null $duration_days
 * @property int|null $job_live_days
 * @property string|null $razorpay_plan_id
 * @property string|null $razorpay_amount
 * @property array<string, mixed>|null $features
 * @property bool $is_active
 */
class Plan extends Model
{
    /** Posts jobs and shows their applicants; may also open the Worker Database. */
    public const TYPE_JOB = 'job';

    /** Opens the Worker Database only. Held on its own or next to a job plan. */
    public const TYPE_DATABASE = 'database';

    /** Runs for `duration_days` at a time: 30, 90, 180 … set by the admin. */
    public const INTERVAL_DAYS = 'days';

    /** The fixed 30-day plans from before `duration_days`; read as 30 days. */
    public const INTERVAL_30_DAYS = '30_days';

    /** Older plans, before the 30-day switch; still read correctly. */
    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_YEARLY = 'yearly';

    protected $fillable = [
        'name', 'tagline', 'slug', 'type', 'price', 'mrp', 'currency', 'interval',
        'duration_days', 'job_live_days', 'razorpay_plan_id', 'razorpay_amount', 'features', 'is_active',
    ];

    /** @var list<string> */
    protected $appends = ['interval_label', 'discount_percent'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'price' => 'decimal:2',
            'mrp' => 'decimal:2',
            'duration_days' => 'integer',
            'job_live_days' => 'integer',
            'razorpay_amount' => 'decimal:2',
        ];
    }

    /**
     * When a period that starts at $start ends.
     */
    public function periodEnd(CarbonInterface $start): CarbonInterface
    {
        return match ($this->interval) {
            self::INTERVAL_YEARLY => $start->copy()->addYear(),
            self::INTERVAL_MONTHLY => $start->copy()->addMonth(),
            default => $start->copy()->addDays($this->durationDays()),
        };
    }

    /**
     * When the period that ends at $end began.
     */
    public function periodStart(CarbonInterface $end): CarbonInterface
    {
        return match ($this->interval) {
            self::INTERVAL_YEARLY => $end->copy()->subYear(),
            self::INTERVAL_MONTHLY => $end->copy()->subMonth(),
            default => $end->copy()->subDays($this->durationDays()),
        };
    }

    /**
     * How many days one period runs: `duration_days`, or 30 for the plans
     * from before it. Monthly and yearly plans are calendar-based and go
     * through periodEnd() instead; this is their rough length.
     */
    public function durationDays(): int
    {
        return match ($this->interval) {
            self::INTERVAL_YEARLY => 365,
            self::INTERVAL_MONTHLY => 30,
            default => max(1, (int) ($this->duration_days ?? 30)),
        };
    }

    /**
     * How long each job posted on this plan stays live. Without a setting a
     * job runs for the whole plan period, as it did before.
     */
    public function jobLiveDays(): int
    {
        return $this->job_live_days ?: $this->durationDays();
    }

    /**
     * "30 days", "90 days", "month" or "year", for "₹1,949 / 30 days".
     *
     * @return Attribute<string, never>
     */
    protected function intervalLabel(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->interval) {
            self::INTERVAL_YEARLY => __('year'),
            self::INTERVAL_MONTHLY => __('month'),
            default => trans_choice(':count day|:count days', $this->durationDays(), ['count' => $this->durationDays()]),
        });
    }

    /**
     * "15" for "15% OFF" next to the struck-out MRP, or null without an MRP
     * above the price.
     *
     * @return Attribute<int|null, never>
     */
    protected function discountPercent(): Attribute
    {
        return Attribute::get(function (): ?int {
            $mrp = (float) $this->mrp;
            $price = (float) $this->price;

            return $mrp > $price && $price > 0 ? (int) round(($mrp - $price) / $mrp * 100) : null;
        });
    }

    public function isDatabase(): bool
    {
        return $this->type === self::TYPE_DATABASE;
    }

    public function jobPostLimit(): int
    {
        return (int) ($this->features['job_post_limit'] ?? 0);
    }

    public function contactUnlockLimit(): int
    {
        return (int) ($this->features['contact_unlock_limit'] ?? 0);
    }

    /**
     * How many worker-database contacts this plan grants access to.
     */
    public function contactDatabaseLimit(): int
    {
        return (int) ($this->features['contact_database_limit'] ?? 0);
    }

    /**
     * Whether this is the plan the catalogue highlights. It is a badge, not
     * something the employer gets, so it is not in {@see featureList()}.
     */
    public function isRecommended(): bool
    {
        return (bool) ($this->features['featured'] ?? false);
    }

    /**
     * What the plan gives, as lines a person reads: "5 job posts per month",
     * "20 database unlocks". The apps show these as they are, so the catalogue
     * never has to know what a limit key means. 0 means unlimited everywhere
     * the limits are enforced, and is said that way here.
     *
     * @return list<string>
     */
    public function featureList(): array
    {
        $per = match ($this->interval) {
            self::INTERVAL_YEARLY => __('per year'),
            self::INTERVAL_MONTHLY => __('per month'),
            default => __('every :count days', ['count' => $this->durationDays()]),
        };

        $jobs = $this->jobPostLimit();
        $unlocks = $this->contactUnlockLimit();
        $database = $this->contactDatabaseLimit();

        // Unlocks open Worker Database numbers only; applicants' numbers
        // show without one.
        $unlockLine = $unlocks > 0
            ? trans_choice(':count database unlock|:count database unlocks', $unlocks, ['count' => number_format($unlocks)]).' '.$per
            : __('Unlimited database unlocks');

        if ($this->isDatabase()) {
            return array_values(array_filter([
                $database > 0 ? __('Access to :count karigar contacts', ['count' => number_format($database)]) : null,
                $unlockLine,
                __('Search karigars by skill and location'),
                __('Works on its own or with a job plan'),
                __('GST invoice for every payment'),
            ]));
        }

        // Says how long each post runs rather than "every 30 days", which
        // read like a monthly drip.
        $live = match (true) {
            $this->job_live_days === null && $this->interval === self::INTERVAL_YEARLY => __('1 year'),
            $this->job_live_days === null && $this->interval === self::INTERVAL_MONTHLY => __('1 month'),
            default => trans_choice(':count day|:count days', $this->jobLiveDays(), ['count' => $this->jobLiveDays()]),
        };

        return array_values(array_filter([
            $jobs > 0
                ? trans_choice(':count job post, live for :period|:count job posts, each live for :period', $jobs, ['count' => number_format($jobs), 'period' => $live])
                : __('Unlimited job posts'),
            $unlockLine,
            $database > 0
                ? __('Access to :count karigar contacts', ['count' => number_format($database)])
                : null,
            __('AI-ranked applicants'),
            __('GST invoice for every payment'),
        ]));
    }

    /**
     * The price the employer pays, GST included, at today's rate.
     */
    public function grossPrice(): float
    {
        return Gst::gross((float) $this->price);
    }

    /**
     * Whether the linked Razorpay plan still charges the right amount. It was
     * made for one price and one GST rate; change either and it is stale.
     */
    public function razorpayPlanIsCurrent(): bool
    {
        // Cleared when the plan's period changes (the 30-day switch).
        return ! empty($this->razorpay_plan_id)
            && $this->razorpay_amount !== null
            && abs((float) $this->razorpay_amount - $this->grossPrice()) < 0.01;
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
