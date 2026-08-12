<?php

namespace App\Enums;

/**
 * What the worker actually said on the screening call. Set from the structured
 * data the voice agent extracts, never guessed from the transcript by hand.
 */
enum ScreeningOutcome: string
{
    case Interested = 'interested';
    case NotInterested = 'not_interested';
    case CallbackLater = 'callback_later';
    case AlreadyPlaced = 'already_placed';
    case Unclear = 'unclear';

    public function label(): string
    {
        return match ($this) {
            self::Interested => 'Interested',
            self::NotInterested => 'Not interested',
            self::CallbackLater => 'Asked to call back',
            self::AlreadyPlaced => 'Already has work',
            self::Unclear => 'Unclear',
        };
    }

    /**
     * Only an interested worker gets an interview slot put forward. Everything
     * else is recorded and left for the employer to look at.
     */
    public function booksInterview(): bool
    {
        return $this === self::Interested;
    }
}
