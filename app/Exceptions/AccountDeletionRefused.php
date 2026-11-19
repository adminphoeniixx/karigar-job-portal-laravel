<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An account that could not be deleted, with nothing deleted. `reason` is the
 * code the apps get: `plan_cancel_failed` when a plan's AutoPay would not stop.
 */
class AccountDeletionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
