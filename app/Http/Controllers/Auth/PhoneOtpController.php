<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Msg91Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PhoneOtpController extends Controller
{
    private const PHONE_RULE = ['required', 'string', 'regex:/^[6-9][0-9]{9}$/'];

    /**
     * The phone + OTP login/register screen for a role.
     */
    public function show(string $role): Response
    {
        return Inertia::render('auth/PhoneOtp', ['role' => $role]);
    }

    /**
     * Send (or resend) an OTP to the given mobile number.
     */
    public function send(Request $request, Msg91Service $msg91): RedirectResponse
    {
        $data = $request->validate([
            'phone' => self::PHONE_RULE,
        ], ['phone.regex' => __('Enter a valid 10-digit mobile number.')]);

        $key = 'otp-send:'.$data['phone'].':'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors([
                'phone' => __('Too many OTPs requested. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        RateLimiter::hit($key, 60);

        $result = $msg91->sendOtp($data['phone']);

        if (! $result['status']) {
            return back()->withErrors(['phone' => $result['message']]);
        }

        return back()->with('otp_sent', true);
    }

    /**
     * Verify the OTP; log the user in — registering them first if needed.
     */
    public function verify(Request $request, string $role, Msg91Service $msg91): RedirectResponse
    {
        abort_unless(in_array($role, ['worker', 'employer'], true), 404);

        $data = $request->validate([
            'phone' => self::PHONE_RULE,
            'otp' => ['required', 'digits:4'],
        ], ['phone.regex' => __('Enter a valid 10-digit mobile number.')]);

        $key = 'otp-verify:'.$data['phone'].':'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors([
                'otp' => __('Too many attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        RateLimiter::hit($key, 60);

        $result = $msg91->verifyOtp($data['phone'], $data['otp']);

        if (! $result['status']) {
            return back()->withErrors(['otp' => $result['message']]);
        }

        RateLimiter::clear($key);

        $user = User::where('phone', $data['phone'])->first();

        if ($user) {
            Auth::login($user, remember: true);

            return redirect()->intended('/dashboard');
        }

        // First OTP login for this number — register the account, then send
        // them to their profile so they can fill in the rest.
        $user = User::create([
            'name' => ucfirst($role).' '.substr($data['phone'], -4),
            'email' => $data['phone'].'@phone.karigar',
            'phone' => $data['phone'],
            'password' => Hash::make(Str::random(40)),
            'role' => $role,
            'email_verified_at' => now(), // phone is the verified identifier here
        ]);

        Auth::login($user, remember: true);

        return redirect($role === 'worker' ? '/worker/profile' : '/employer/profile')
            ->with('toast', [
                'type' => 'success',
                'message' => __('Welcome to Karigar! Complete your profile to get started.'),
            ]);
    }
}
