<?php

namespace App\Enums;

enum CallStatus: string
{
    case Ringing = 'ringing';
    case Answered = 'answered';
    case Ended = 'ended';
    case Rejected = 'rejected';
    case Missed = 'missed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Ringing => 'Ringing',
            self::Answered => 'In call',
            self::Ended => 'Completed',
            self::Rejected => 'Declined',
            self::Missed => 'Missed',
            self::Failed => 'Failed',
        };
    }

    /**
     * A call that is still live — either ringing on the callee's phone or
     * connected. Only these can be answered, rejected or ended.
     */
    public function isOpen(): bool
    {
        return $this === self::Ringing || $this === self::Answered;
    }
}
