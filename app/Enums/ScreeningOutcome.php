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

    /**
     * The worker ruled themselves out on the call, so the application is over
     * and is rejected on the spot. Deliberately only the two answers that are
     * unambiguous: "call me back later" and "unclear" leave the application
     * exactly where it was, because a mis-heard sentence must never cost a
     * worker a job they still want.
     */
    public function closesApplication(): bool
    {
        return $this === self::NotInterested || $this === self::AlreadyPlaced;
    }
}
