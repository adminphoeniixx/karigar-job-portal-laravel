<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use App\Services\Msg91Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mobile OTP authentication for the worker app. Reuses the same MSG91 flow
 * as the web PhoneOtpController, but returns a Sanctum token instead of
 * starting a cookie session.
 */
class OtpAuthController extends Controller
{
    private const PHONE_RULE = ['required', 'string', 'regex:/^[6-9][0-9]{9}$/'];

    /**
     * Send (or resend) an OTP to the given mobile number.
     */
    public function send(Request $request, Msg91Service $msg91): JsonResponse
    {
        $data = $request->validate([
            'phone' => self::PHONE_RULE,
        ], ['phone.regex' => __('Enter a valid 10-digit mobile number.')]);

        $key = 'otp-send:'.$data['phone'].':'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'phone' => __('Too many OTPs requested. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        RateLimiter::hit($key, 60);

        $result = $msg91->sendOtp($data['phone']);

        if (! $result['status']) {
            throw ValidationException::withMessages(['phone' => $result['message']]);
        }

        return response()->json([
            'message' => $result['message'],
            'cooldown' => 30,
        ]);
    }

    /**
     * Verify the OTP; issue an API token — registering the account first if
     * this is the number's first login.
     */
    public function verify(Request $request, Msg91Service $msg91): JsonResponse
    {
        $data = $request->validate([
            'phone' => self::PHONE_RULE,
            'otp' => ['required', 'digits:4'],
            'role' => ['required', 'in:worker,employer'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], ['phone.regex' => __('Enter a valid 10-digit mobile number.')]);

        $key = 'otp-verify:'.$data['phone'].':'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'otp' => __('Too many attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        RateLimiter::hit($key, 60);

        $result = $msg91->verifyOtp($data['phone'], $data['otp']);

        if (! $result['status']) {
            throw ValidationException::withMessages(['otp' => $result['message']]);
        }

        RateLimiter::clear($key);

        $user = User::where('phone', $data['phone'])->first();
        $isNew = false;

        if (! $user) {
            $isNew = true;
            $user = User::create([
                'name' => ucfirst($data['role']).' '.substr($data['phone'], -4),
                'email' => $data['phone'].'@phone.karigar',
                'phone' => $data['phone'],
                'password' => Hash::make(Str::random(40)),
                'role' => $data['role'],
                'email_verified_at' => now(),
            ]);
        }

        abort_if($user->isSuspended(), 403, __('Your account has been suspended.'));

        $token = $user->createToken($data['device_name'] ?? 'worker-app')->plainTextToken;

        // Ensure a worker has a profile row to fill in.
        if ($user->isWorker()) {
            $user->workerProfile()->firstOrCreate([]);
        }

        return response()->json([
            'token' => $token,
            'is_new' => $isNew,
            // New workers should be routed into the registration wizard.
            'needs_registration' => $isNew && $user->isWorker(),
            'user' => new UserResource($user->load('workerProfile')),
        ]);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('Logged out.')]);
    }

    /**
     * The authenticated user with profile + quick counts for the app shell.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('workerProfile');

        return response()->json([
            'user' => new UserResource($user),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ]);
    }
}
