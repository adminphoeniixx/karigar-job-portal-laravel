<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\KycResource;
use App\Models\KycCheck;
use App\Models\User;
use App\Services\Kyc\KycAutoVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aadhaar through DigiLocker, for both apps. The app opens
 * `authorization_url` in an in-app browser; once that browser reaches a URL
 * starting with `return_url_prefix` the user is done, and the app closes it
 * and asks {@see status()} how it went.
 *
 * An employer's team member verifies the account owner, as with the rest of
 * business verification.
 */
class KycDigilockerController extends Controller
{
    public function start(Request $request, KycAutoVerifier $verifier): JsonResponse
    {
        if (! KycAutoVerifier::enabled()) {
            return response()->json([
                'message' => __('DigiLocker verification is not available right now. Enter your Aadhaar number instead.'),
                'code' => 'digilocker_unavailable',
            ], 422);
        }

        $check = $verifier->startDigilocker($this->account($request));
        $url = $check->details['authorization_url'] ?? null;

        if ($check->status === KycCheck::ERROR || $url === null) {
            return response()->json(['message' => $check->message, 'code' => 'digilocker_failed'], 422);
        }

        return response()->json([
            'reference' => $check->reference,
            'authorization_url' => $url,
            'return_url_prefix' => url('/kyc/digilocker/return').'/',
            'expires_in_minutes' => KycCheck::DIGILOCKER_TTL_MINUTES,
        ], 201);
    }

    /**
     * How one DigiLocker attempt went: initiated (user not back yet), passed,
     * invalid or error. A pending submission may have been verified by it,
     * so the current KYC comes along too.
     */
    public function status(Request $request, string $reference): JsonResponse
    {
        $account = $this->account($request);

        $check = KycCheck::where('reference', $reference)
            ->where('method', KycCheck::METHOD_DIGILOCKER)
            ->where('user_id', $account->id)
            ->firstOrFail();

        $last4 = $check->details['last4'] ?? null;
        // Fresh: finishing DigiLocker may just have verified a waiting submission.
        $kyc = $account->kyc()->first();

        return response()->json([
            'reference' => $check->reference,
            'status' => $check->status,
            'name' => $check->passed() ? $check->name : null,
            'masked_aadhaar' => $last4 ? 'XXXX XXXX '.$last4 : null,
            'message' => $check->message,
            'kyc' => $kyc ? new KycResource($kyc) : null,
        ]);
    }

    private function account(Request $request): User
    {
        $user = $request->user();

        return $user->isEmployer() ? $user->employerAccount() : $user;
    }
}
