<?php

namespace App\Enums;

enum EscrowStatus: string
{
    case Pending = 'pending';                 // created, awaiting employer payment
    case Funded = 'funded';                   // employer paid, platform holding funds
    case ReleaseRequested = 'release_requested'; // employer confirmed completion, awaiting payout
    case Released = 'released';               // paid out to worker
    case Refunded = 'refunded';               // returned to employer
    case Disputed = 'disputed';               // flagged, admin to resolve

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Funded => 'Funds held',
            self::ReleaseRequested => 'Release requested',
            self::Released => 'Paid to worker',
            self::Refunded => 'Refunded',
            self::Disputed => 'Disputed',
        };
    }
}
