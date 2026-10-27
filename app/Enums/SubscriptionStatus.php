<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Created = 'created';
    case Authenticated = 'authenticated';
    case Active = 'active';
    case Pending = 'pending';
    case Halted = 'halted';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Expired = 'expired';

    /**
     * Statuses that grant access to paid features.
     *
     * @return array<int, self>
     */
    public static function entitled(): array
    {
        return [self::Authenticated, self::Active];
    }

    public function isEntitled(): bool
    {
        return in_array($this, self::entitled(), true);
    }

    /**
     * Statuses that will not renew but keep the period already paid for:
     * cancelled (say, the employer stopped the UPI mandate) or completed
     * (every cycle charged). They grant access until `ends_at`.
     *
     * @return array<int, self>
     */
    public static function runningOut(): array
    {
        return [self::Cancelled, self::Completed];
    }

    public function isRunningOut(): bool
    {
        return in_array($this, self::runningOut(), true);
    }
}
