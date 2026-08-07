<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends and verifies login OTPs over MSG91's flow API.
 *
 * The generated OTP is always cached for 15 minutes so that local/dev
 * environments (no MSG91 keys) can still complete the flow — the OTP is
 * written to the log instead of being SMSed.
 *
 * One number can be exempted from all of that for app testing: set both
 * AUTH_TEST_PHONE and AUTH_TEST_OTP and that number logs in with the fixed OTP
 * without any SMS. It is a deliberate auth bypass — leave the values blank
 * unless a test login is actually needed.
 */
class Msg91Service
{
    private const TTL_MINUTES = 15;

    public function configured(): bool
    {
        return (bool) (config('services.msg91.authkey') && config('services.msg91.template_id'));
    }

    /**
     * @return array{status: bool, message: string}
     */
    public function sendOtp(string $phone): array
    {
        // The test number has a fixed OTP — send nothing, and in particular do
        // not cache a random one over it.
        if ($this->isTestPhone($phone)) {
            Log::info("Test login number {$phone} — no OTP sent.");

            return ['status' => true, 'message' => 'OTP sent successfully'];
        }

        $otp = random_int(1111, 9999);

        Cache::put($this->cacheKey($phone), (string) $otp, now()->addMinutes(self::TTL_MINUTES));

        if (! $this->configured()) {
            Log::info("MSG91 not configured — OTP for {$phone} is {$otp}");

            return app()->environment('production')
                ? ['status' => false, 'message' => 'SMS service is not configured yet.']
                : ['status' => true, 'message' => 'OTP sent (dev mode — see log).'];
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders(['content-type' => 'application/json'])
                ->post('https://api.msg91.com/api/v5/otp?'.http_build_query([
                    'template_id' => config('services.msg91.template_id'),
                    'mobile' => '91'.$phone,
                    'authkey' => config('services.msg91.authkey'),
                ]), ['otp' => $otp]);

            $data = $response->json();
            Log::info('MSG91 OTP response: '.$response->body());

            return isset($data['type']) && $data['type'] === 'success'
                ? ['status' => true, 'message' => 'OTP sent successfully']
                : ['status' => false, 'message' => $data['message'] ?? 'Failed to send OTP'];
        } catch (Throwable $e) {
            Log::error('Send OTP error: '.$e->getMessage());

            return ['status' => false, 'message' => 'Failed to send OTP'];
        }
    }

    /**
     * @return array{status: bool, message: string}
     */
    public function verifyOtp(string $phone, string $otp): array
    {
        if ($this->isTestPhone($phone)) {
            return hash_equals((string) config('services.msg91.test_otp'), $otp)
                ? ['status' => true, 'message' => 'OTP verified successfully']
                : ['status' => false, 'message' => 'Invalid or expired OTP.'];
        }

        // Local check first: covers dev mode and saves an API call when wrong.
        $cached = Cache::get($this->cacheKey($phone));

        if ($cached !== null && hash_equals($cached, $otp)) {
            Cache::forget($this->cacheKey($phone));

            return ['status' => true, 'message' => 'OTP verified successfully'];
        }

        if (! $this->configured()) {
            return ['status' => false, 'message' => 'Invalid or expired OTP.'];
        }

        try {
            $response = Http::timeout(30)->get('https://api.msg91.com/api/v5/otp/verify', [
                'authkey' => config('services.msg91.authkey'),
                'mobile' => '91'.$phone,
                'otp' => $otp,
            ]);

            $data = $response->json();
            Log::info('MSG91 OTP verify response: '.$response->body());

            if (isset($data['type']) && $data['type'] === 'error') {
                return ['status' => false, 'message' => $data['message'] ?? 'OTP verification failed'];
            }

            Cache::forget($this->cacheKey($phone));

            return ['status' => true, 'message' => 'OTP verified successfully'];
        } catch (Throwable $e) {
            Log::error('Verify OTP error: '.$e->getMessage());

            return ['status' => false, 'message' => 'OTP verification failed'];
        }
    }

    private function cacheKey(string $phone): string
    {
        return "phone_otp.{$phone}";
    }

    /**
     * The app-testing number, if one is configured. Both env values must be
     * set, so the bypass stays off unless it is switched on deliberately.
     */
    private function isTestPhone(string $phone): bool
    {
        $testPhone = (string) config('services.msg91.test_phone');
        $testOtp = (string) config('services.msg91.test_otp');

        return $testPhone !== '' && $testOtp !== '' && hash_equals($testPhone, $phone);
    }
}
