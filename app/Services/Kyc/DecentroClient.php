<?php

namespace App\Services\Kyc;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Decentro's KYC APIs, the three we use:
 *
 *  - public_registry/validate: a PAN or GSTIN against the government record
 *  - digilocker/initiate_session: a link the user opens to sign in to DigiLocker
 *  - digilocker/eaadhaar: the verified Aadhaar that sign-in shared with us
 *
 * Every call returns a {@see DecentroResult}; nothing here throws on a
 * provider failure, so a Decentro outage only sends submissions to an admin.
 */
class DecentroClient
{
    public static function configured(): bool
    {
        return filled(config('services.decentro.client_id')) && filled(config('services.decentro.client_secret'));
    }

    /**
     * Check a PAN (`PAN`) or GSTIN (`GSTIN`) against the registry.
     */
    public function validate(string $documentType, string $number, string $reference, string $purpose): DecentroResult
    {
        return $this->post('kyc/public_registry/validate', [
            'reference_id' => $reference,
            'document_type' => $documentType,
            'id_number' => $number,
            'consent' => 'Y',
            'consent_purpose' => $purpose,
        ], resultKey: 'kycResult');
    }

    /**
     * Start a DigiLocker session; the result's data holds `authorizationUrl`.
     */
    public function initiateDigilocker(string $reference, string $redirectUrl, string $purpose): DecentroResult
    {
        return $this->post('v2/kyc/digilocker/initiate_session', [
            'reference_id' => $reference,
            'consent' => true,
            'consent_purpose' => $purpose,
            'redirect_url' => $redirectUrl,
            // Aadhaar sign-in/sign-up, so a user with no DigiLocker account yet gets one.
            'redirect_to_signup' => true,
        ], resultKey: 'data');
    }

    /**
     * The eAadhaar the user shared in the session started with `$initialTxnId`.
     */
    public function eAadhaar(string $initialTxnId, string $reference, string $purpose): DecentroResult
    {
        return $this->post('v2/kyc/digilocker/eaadhaar', [
            'initial_decentro_transaction_id' => $initialTxnId,
            'reference_id' => $reference,
            'consent' => true,
            'consent_purpose' => $purpose,
        ], resultKey: 'data');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body, string $resultKey): DecentroResult
    {
        if (! self::configured()) {
            return DecentroResult::error(__('Automatic verification is not set up.'));
        }

        try {
            $response = $this->http()->post($path, $body);
        } catch (ConnectionException $e) {
            Log::warning('Decentro unreachable', ['path' => $path, 'error' => $e->getMessage()]);

            return DecentroResult::error(__('The verification service did not answer. Our team will check this by hand.'));
        }

        $json = $response->json() ?? [];
        $txnId = $json['decentroTxnId'] ?? $json['decentro_txn_id'] ?? null;
        $key = (string) ($json['responseKey'] ?? '');
        $message = (string) ($json['message'] ?? $json['error']['message'] ?? '');
        $succeeded = ($json['status'] ?? null) === 'SUCCESS'
            && ($json['kycStatus'] ?? 'SUCCESS') === 'SUCCESS'
            && is_array($json[$resultKey] ?? null);

        if ($succeeded) {
            return DecentroResult::ok($json[$resultKey], $txnId, $key);
        }

        // The registry answered "no such number": the user's mistake, not ours.
        if (DecentroResult::isNotFoundKey($key)) {
            return DecentroResult::notFound($message, $txnId, $key);
        }

        Log::warning('Decentro call failed', [
            'path' => $path,
            'http' => $response->status(),
            'key' => $key,
            'message' => $message,
            'txn' => $txnId,
        ]);

        return DecentroResult::error($message ?: __('The verification service could not check this.'), $txnId, $key);
    }

    private function http(): PendingRequest
    {
        $headers = [
            'client_id' => (string) config('services.decentro.client_id'),
            'client_secret' => (string) config('services.decentro.client_secret'),
        ];

        if (filled(config('services.decentro.module_secret'))) {
            $headers['module_secret'] = (string) config('services.decentro.module_secret');
        }

        return Http::baseUrl(config('services.decentro.base_url'))
            ->withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->timeout(config('services.decentro.timeout', 25));
    }
}
