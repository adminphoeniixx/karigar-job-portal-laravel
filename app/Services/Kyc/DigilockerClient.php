<?php

namespace App\Services\Kyc;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DigiLocker sign-in through MeriPehchaan (Requester – Meri Pehchaan API
 * Specification v2.3, OAuth 2.0 with PKCE + OpenID), our own API Setu
 * credentials and no middleman:
 *
 *  1. send the user to {@see authorizationUrl()}; DigiLocker brings them back
 *     to the registered callback with `code` + `state` (or `error`)
 *  2. {@see token()} swaps the code for an access token and an `id_token`
 *     carrying the masked Aadhaar and, when DigiLocker has it, the PAN
 *  3. {@see eAadhaar()} reads the e-Aadhaar XML with that token
 *
 * Failures throw {@see DigilockerException}; the caller turns them into a
 * message for the user.
 */
class DigilockerClient
{
    public static function configured(): bool
    {
        return filled(config('services.digilocker.client_id')) && filled(config('services.digilocker.client_secret'));
    }

    /**
     * Where DigiLocker sends the user back; must equal the Call Back URL
     * registered on API Setu.
     */
    public static function redirectUri(): string
    {
        return config('services.digilocker.redirect_uri') ?: route('kyc.digilocker.callback');
    }

    /**
     * A fresh PKCE code verifier: 64 characters, well inside the 43–128 allowed.
     */
    public static function newCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    /**
     * The DigiLocker sign-in page. `$state` comes back on the callback and is
     * how we find the attempt again.
     */
    public function authorizationUrl(string $state, string $codeVerifier): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return config('services.digilocker.base_url').'/public/oauth2/1/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.digilocker.client_id'),
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            // OpenID: the token answer carries an id_token with the masked Aadhaar and PAN.
            'scope' => 'openid',
            'purpose' => 'kyc',
        ]);
    }

    /**
     * Swap the callback's code for tokens.
     *
     * @return array<string, mixed> access_token, id_token, …
     */
    public function token(string $code, string $codeVerifier): array
    {
        $response = $this->send(fn () => $this->http()->acceptJson()->asForm()->post('/public/oauth2/2/token', [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => config('services.digilocker.client_id'),
            'client_secret' => config('services.digilocker.client_secret'),
            'redirect_uri' => self::redirectUri(),
            'code_verifier' => $codeVerifier,
        ]), 'token');

        $json = $response->json() ?? [];

        if (! is_string($json['access_token'] ?? null)) {
            throw new DigilockerException(__('DigiLocker did not finish signing you in. Try again.'), 'no_access_token');
        }

        return $json;
    }

    /**
     * The e-Aadhaar XML of the signed-in account, checked against the HMAC
     * DigiLocker signs it with.
     */
    public function eAadhaar(string $accessToken): string
    {
        $response = $this->send(fn () => $this->http()->withToken($accessToken)->get('/public/oauth2/3/xml/eaadhaar'), 'eaadhaar');
        $xml = $response->body();
        $hmac = $response->header('hmac');

        if ($hmac !== '' && ! hash_equals(base64_encode(hash_hmac('sha256', $xml, (string) config('services.digilocker.client_secret'), true)), $hmac)) {
            Log::warning('DigiLocker e-Aadhaar HMAC mismatch');

            throw new DigilockerException(__('DigiLocker sent an Aadhaar we could not trust. Try again.'), 'hmac_mismatch');
        }

        return $xml;
    }

    /**
     * The claims of an id_token. It came to us straight from DigiLocker's
     * token endpoint over TLS, so the payload is read as is.
     *
     * @return array<string, mixed>
     */
    public static function claims(?string $idToken): array
    {
        $payload = explode('.', (string) $idToken)[1] ?? '';
        $json = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        return is_array($json) ? $json : [];
    }

    /**
     * What we keep from the e-Aadhaar: the name, the last four digits and the
     * district/state/PIN, never the full number, photo or street address.
     *
     * @return array{name: ?string, last4: ?string, dob: ?string, gender: ?string, district: ?string, state: ?string, pincode: ?string}
     */
    public static function readEAadhaar(string $xml): array
    {
        $doc = new DOMDocument;

        if (trim($xml) === '' || ! @$doc->loadXML($xml, LIBXML_NONET)) {
            throw new DigilockerException(__('DigiLocker did not share an Aadhaar.'), 'unreadable_eaadhaar');
        }

        $xpath = new DOMXPath($doc);
        $first = function (string $name) use ($xpath): ?DOMElement {
            $node = $xpath->query("//*[local-name()='{$name}']")->item(0);

            return $node instanceof DOMElement ? $node : null;
        };
        $attr = fn (?DOMElement $el, string $name): ?string => $el?->hasAttribute($name) && trim($el->getAttribute($name)) !== ''
            ? trim($el->getAttribute($name))
            : null;

        $uid = preg_replace('/\D+/', '', (string) $attr($first('UidData'), 'uid'));
        $poi = $first('Poi');
        $poa = $first('Poa');

        return [
            'name' => $attr($poi, 'name'),
            'last4' => strlen($uid) >= 4 ? substr($uid, -4) : null,
            'dob' => $attr($poi, 'dob'),
            'gender' => $attr($poi, 'gender'),
            'district' => $attr($poa, 'dist'),
            'state' => $attr($poa, 'state'),
            'pincode' => $attr($poa, 'pc'),
        ];
    }

    /**
     * @param  callable(): Response  $call
     */
    private function send(callable $call, string $step): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            Log::warning('DigiLocker unreachable', ['step' => $step, 'error' => $e->getMessage()]);

            throw new DigilockerException(__('DigiLocker did not answer. Try again in a minute.'), 'unreachable');
        }

        if ($response->failed()) {
            $error = (string) ($response->json('error') ?? 'http_'.$response->status());
            $description = (string) ($response->json('error_description') ?? '');

            Log::warning('DigiLocker call failed', [
                'step' => $step,
                'http' => $response->status(),
                'error' => $error,
                'description' => $description,
            ]);

            throw new DigilockerException($description ?: __('DigiLocker could not share your details.'), $error);
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.digilocker.base_url'))
            ->timeout(config('services.digilocker.timeout', 20));
    }
}
