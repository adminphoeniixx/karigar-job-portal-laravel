<?php

namespace App\Http\Responses;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->intended($this->homeFor($request->user()?->role));
    }

    public static function homeFor(?UserRole $role): string
    {
        return match ($role) {
            UserRole::Employer => route('jobs.index'),
            UserRole::Admin => route('admin.kyc.index'),
            default => route('worker.profile.edit'),
        };
    }
}
