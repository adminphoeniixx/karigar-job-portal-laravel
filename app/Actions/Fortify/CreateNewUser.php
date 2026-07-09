<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Email/password registration is retired — every worker and employer
     * account is created through the mobile-number + OTP flow instead.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        throw ValidationException::withMessages([
            'email' => __('Registration now happens with your mobile number. Please sign up with OTP.'),
        ]);
    }
}
