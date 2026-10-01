<?php

namespace App\Http\Requests;

/**
 * Business verification from the employer app: the shared rules, employers only.
 */
class EmployerKycRequest extends KycSubmitRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isEmployer() ?? false;
    }
}
