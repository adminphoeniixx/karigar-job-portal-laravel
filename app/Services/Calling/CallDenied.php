<?php

namespace App\Services\Calling;

use RuntimeException;

/**
 * A call that was refused for a reason the app should show the user — not
 * allowed to call this person, already on a call, dialled too often today.
 * The `reason` is a stable machine code the Flutter side switches on; the
 * message is what gets shown.
 */
class CallDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function notAllowed(): self
    {
        return new self('not_allowed', __('You can only call people you have a job application with.'), 403);
    }

    public static function unavailable(): self
    {
        return new self('calling_unavailable', __('Calling is not available right now.'), 503);
    }

    public static function busy(): self
    {
        return new self('busy', __('One of you is already on a call.'));
    }

    public static function selfCall(): self
    {
        return new self('self_call', __('You cannot call yourself.'));
    }

    public static function rateLimited(): self
    {
        return new self('rate_limited', __('You have made too many calls today. Try again tomorrow.'), 429);
    }

    public static function notOpen(): self
    {
        return new self('call_not_open', __('This call has already ended.'));
    }
}
