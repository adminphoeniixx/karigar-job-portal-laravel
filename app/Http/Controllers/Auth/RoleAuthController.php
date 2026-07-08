<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class RoleAuthController extends Controller
{
    public function login(Request $request, string $role): Response
    {
        $this->rememberIntended($request);

        return Inertia::render('auth/Login', [
            'role' => $role,
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function register(Request $request, string $role): Response
    {
        $this->rememberIntended($request);

        return Inertia::render('auth/Register', [
            'role' => $role,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Persist a safe, in-app `redirect` target so Fortify's
     * redirect()->intended() returns the user there after auth.
     */
    private function rememberIntended(Request $request): void
    {
        $redirect = (string) $request->query('redirect', '');

        // Only allow internal relative paths (no protocol-relative "//").
        if (str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
            $request->session()->put('url.intended', $redirect);
        }
    }
}
