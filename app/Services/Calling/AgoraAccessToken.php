<?php

namespace App\Services\Calling;

use InvalidArgumentException;
use RuntimeException;

/**
 * Agora AccessToken2 ("007") builder, RTC service only.
 *
 * This is a transcription of Agora's reference implementation
 * (AgoraIO/Tools · DynamicKey/AgoraDynamicKey/php/src/AccessToken2.php),
 * trimmed to the single service this app needs. Agora's servers re-derive the
 * signature from these exact bytes, so the field order, the little-endian
 * packing and the two-step HMAC key derivation are a wire format — don't
 * "clean them up".
 */
final class AgoraAccessToken
{
    private const VERSION = '007';

    private const SERVICE_RTC = 1;

    private const PRIVILEGE_JOIN_CHANNEL = 1;

    private const PRIVILEGE_PUBLISH_AUDIO_STREAM = 2;

    /**
     * Build a token letting `$uid` join `$channel` and publish audio for
     * `$ttl` seconds. Video and data-stream privileges are deliberately left
     * out — these are voice calls.
     *
     * `$issuedAt` and `$salt` are only ever passed by tests; in production both
     * come from the clock and the CSPRNG.
     *
     * @param  int  $uid  Agora user id; 0 means "any uid" (not used here).
     */
    public static function rtc(
        string $appId,
        string $appCertificate,
        string $channel,
        int $uid,
        int $ttl,
        ?int $issuedAt = null,
        ?int $salt = null,
    ): string {
        if (! self::isHex32($appId) || ! self::isHex32($appCertificate)) {
            throw new InvalidArgumentException('Agora app id and certificate must both be 32-character hex strings.');
        }

        $issuedAt ??= time();
        $salt ??= random_int(1, 99999999);

        // Both expiries are durations in seconds, not absolute timestamps.
        $privileges = [
            self::PRIVILEGE_JOIN_CHANNEL => $ttl,
            self::PRIVILEGE_PUBLISH_AUDIO_STREAM => $ttl,
        ];

        $service = self::uint16(self::SERVICE_RTC)
            .self::map($privileges)
            .self::string($channel)
            .self::string($uid === 0 ? '' : (string) $uid);

        $data = self::string($appId)
            .self::uint32($issuedAt)
            .self::uint32($ttl)
            .self::uint32($salt)
            .self::uint16(1) // service count
            .$service;

        $signature = hash_hmac('sha256', $data, self::signingKey($appCertificate, $issuedAt, $salt), true);

        $compressed = zlib_encode(self::string($signature).$data, ZLIB_ENCODING_DEFLATE);

        if ($compressed === false) {
            // Silently emitting an empty token would fail much later, inside
            // the SDK on the worker's phone, with nothing to point at.
            throw new RuntimeException('Could not compress the Agora access token payload.');
        }

        return self::VERSION.base64_encode($compressed);
    }

    /**
     * The signing key: the certificate HMAC'd under the issue timestamp, then
     * under the salt. Note the argument order — the timestamp and salt are the
     * *keys*, the certificate is the *message*.
     */
    private static function signingKey(string $appCertificate, int $issuedAt, int $salt): string
    {
        $stage = hash_hmac('sha256', $appCertificate, self::uint32($issuedAt), true);

        return hash_hmac('sha256', $stage, self::uint32($salt), true);
    }

    private static function isHex32(string $value): bool
    {
        return strlen($value) === 32 && ctype_xdigit($value);
    }

    private static function uint16(int $value): string
    {
        return pack('v', $value);
    }

    private static function uint32(int $value): string
    {
        return pack('V', $value);
    }

    /**
     * A string with a uint16 length prefix.
     */
    private static function string(string $value): string
    {
        return self::uint16(strlen($value)).$value;
    }

    /**
     * A uint16 => uint32 map: count first, then the pairs in ascending key
     * order (Agora sorts before signing, so we must too).
     *
     * @param  array<int, int>  $map
     */
    private static function map(array $map): string
    {
        ksort($map);

        $packed = '';
        foreach ($map as $key => $value) {
            $packed .= self::uint16($key).self::uint32($value);
        }

        return self::uint16(count($map)).$packed;
    }
}
