<?php

namespace App\Models;

use App\Services\Billing\Gst;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $type
 * @property string $price
 * @property string $currency
 * @property string $interval
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

    protected $fillable = [
        'name', 'slug', 'type', 'price', 'currency', 'interval',
        'razorpay_plan_id', 'razorpay_amount', 'features', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'price' => 'decimal:2',
            'razorpay_amount' => 'decimal:2',
        ];
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
     * "20 contact unlocks". The apps show these as they are, so the catalogue
     * never has to know what a limit key means. 0 means unlimited everywhere
     * the limits are enforced, and is said that way here.
     *
     * @return list<string>
     */
    public function featureList(): array
    {
        $per = $this->interval === 'yearly' ? __('per year') : __('per month');

        $jobs = $this->jobPostLimit();
        $unlocks = $this->contactUnlockLimit();
        $database = $this->contactDatabaseLimit();

        $unlockLine = $unlocks > 0
            ? trans_choice(':count contact unlock|:count contact unlocks', $unlocks, ['count' => number_format($unlocks)]).' '.$per
            : __('Unlimited contact unlocks');

        if ($this->isDatabase()) {
            return array_values(array_filter([
                $database > 0 ? __('Access to :count karigar contacts', ['count' => number_format($database)]) : null,
                $unlockLine,
                __('Search karigars by skill and location'),
                __('Works on its own or with a job plan'),
                __('GST invoice for every payment'),
            ]));
        }

        return array_values(array_filter([
            $jobs > 0
                ? trans_choice(':count job post|:count job posts', $jobs, ['count' => number_format($jobs)]).' '.$per
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
