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
}
