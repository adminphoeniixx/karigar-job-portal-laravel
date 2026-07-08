<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $price
 * @property string $currency
 * @property string $interval
 * @property string|null $razorpay_plan_id
 * @property array<string, mixed>|null $features
 * @property bool $is_active
 */
class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'price', 'currency', 'interval',
        'razorpay_plan_id', 'features', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'price' => 'decimal:2',
        ];
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
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
