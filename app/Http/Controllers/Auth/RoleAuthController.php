<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class RoleAuthController extends Controller
{
    public function login(Request $request, string $role): Response|RedirectResponse
    {
        $this->rememberIntended($request);

        // Workers & employers sign in with mobile OTP only.
        if ($role !== 'admin') {
            return redirect()->route('otp.show', $role);
        }

        return Inertia::render('auth/Login', [
            'role' => $role,
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function register(Request $request, string $role): RedirectResponse
    {
        $this->rememberIntended($request);

        // Registration happens through the mobile OTP flow.
        return redirect()->route('otp.show', $role);
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
