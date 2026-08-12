<?php

use App\Services\Calling\AgoraAccessToken;

/**
 * Agora re-derives the signature from the exact bytes we emit, so these tests
 * unpack a built token the same way Agora's server does and check every field
 * lands where the "007" format says it should. A green run here does not prove
 * the credentials are valid — only that the encoding is.
 */
const APP_ID = 'abcdef0123456789abcdef0123456789';

const APP_CERT = '0123456789abcdef0123456789abcdef';

/**
 * Mirror of Agora's AccessToken2::parse — pull the token apart field by field.
 *
 * @return array<string, mixed>
 */
function unpackAgoraToken(string $token): array
{
    expect(substr($token, 0, 3))->toBe('007');

    $data = zlib_decode(base64_decode(substr($token, 3)));

    $uint16 = function () use (&$data): int {
        $value = unpack('v', substr($data, 0, 2))[1];
        $data = substr($data, 2);

        return $value;
    };
    $uint32 = function () use (&$data): int {
        $value = unpack('V', substr($data, 0, 4))[1];
        $data = substr($data, 4);

        return $value;
    };
    $string = function () use (&$data, $uint16): string {
        $length = $uint16();
        $value = substr($data, 0, $length);
        $data = substr($data, $length);

        return $value;
    };

    $signature = $string();
    // Everything after the signature is what gets signed.
    $signed = $data;

    $appId = $string();
    $issuedAt = $uint32();
    $expire = $uint32();
    $salt = $uint32();
    $serviceCount = $uint16();

    $serviceType = $uint16();
    $privilegeCount = $uint16();
    $privileges = [];
    for ($i = 0; $i < $privilegeCount; $i++) {
        $privileges[$uint16()] = $uint32();
    }

    return [
        'signature' => $signature,
        'signed' => $signed,
        'app_id' => $appId,
        'issued_at' => $issuedAt,
        'expire' => $expire,
        'salt' => $salt,
        'service_count' => $serviceCount,
        'service_type' => $serviceType,
        'privileges' => $privileges,
        'channel' => $string(),
        'uid' => $string(),
    ];
}

it('packs the app id, timings and rtc service into a 007 token', function () {
    $token = AgoraAccessToken::rtc(APP_ID, APP_CERT, 'sk-channel', 4242, 3600, issuedAt: 1_700_000_000, salt: 12345);

    $parsed = unpackAgoraToken($token);

    expect($parsed['app_id'])->toBe(APP_ID)
        ->and($parsed['issued_at'])->toBe(1_700_000_000)
        ->and($parsed['expire'])->toBe(3600)
        ->and($parsed['salt'])->toBe(12345)
        ->and($parsed['service_count'])->toBe(1)
        ->and($parsed['service_type'])->toBe(1) // RTC
        ->and($parsed['channel'])->toBe('sk-channel')
        ->and($parsed['uid'])->toBe('4242');
});

it('grants join and audio-publish privileges only', function () {
    $token = AgoraAccessToken::rtc(APP_ID, APP_CERT, 'sk-channel', 7, 900, issuedAt: 1_700_000_000, salt: 1);

    // 1 = join channel, 2 = publish audio. Video (3) and data (4) are withheld:
    // these are voice calls.
    expect(unpackAgoraToken($token)['privileges'])->toBe([1 => 900, 2 => 900]);
});

it('signs with the certificate keyed by issue timestamp then salt', function () {
    $token = AgoraAccessToken::rtc(APP_ID, APP_CERT, 'sk-channel', 7, 900, issuedAt: 1_700_000_000, salt: 99);

    $parsed = unpackAgoraToken($token);

    // Agora's derivation: HMAC the certificate under the timestamp, then HMAC
    // that under the salt. The timestamp and salt are the keys, not the data.
    $stage = hash_hmac('sha256', APP_CERT, pack('V', 1_700_000_000), true);
    $signingKey = hash_hmac('sha256', $stage, pack('V', 99), true);

    expect($parsed['signature'])->toBe(hash_hmac('sha256', $parsed['signed'], $signingKey, true));
});

it('produces a different token every call so channels cannot be replayed', function () {
    $first = AgoraAccessToken::rtc(APP_ID, APP_CERT, 'sk-channel', 7, 900);
    $second = AgoraAccessToken::rtc(APP_ID, APP_CERT, 'sk-channel', 7, 900);

    expect($first)->not->toBe($second);
});

it('rejects credentials that are not 32-character hex', function () {
    AgoraAccessToken::rtc('too-short', APP_CERT, 'sk-channel', 7, 900);
})->throws(InvalidArgumentException::class);
