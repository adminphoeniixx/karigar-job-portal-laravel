<?php

namespace App\Enums;

enum ScreeningCallStatus: string
{
    /** Booked for `scheduled_at`; dialled by `screening:place-due`. */
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Dialing = 'dialing';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Queued => 'Queued',
            self::Dialing => 'Dialing',
            self::InProgress => 'On call',
            self::Completed => 'Completed',
            self::NoAnswer => 'No answer',
            self::Busy => 'Busy',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The worker never picked up, so trying again later is reasonable.
     */
    public function isRetryable(): bool
    {
        return $this === self::NoAnswer || $this === self::Busy || $this === self::Failed;
    }

    /**
     * The call is over, one way or another.
     */
    public function isFinished(): bool
    {
        return ! in_array($this, [self::Scheduled, self::Queued, self::Dialing, self::InProgress], true);
    }
}
