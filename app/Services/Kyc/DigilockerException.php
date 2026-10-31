<?php

namespace App\Services\Kyc;

use RuntimeException;

/**
 * DigiLocker said no, or did not answer. `error` is its OAuth error code
 * (`invalid_grant`, `aadhaar_not_linked`, …) when it gave one.
 */
class DigilockerException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $error = null)
    {
        parent::__construct($message);
    }
}
