<?php

namespace App\Services\Calling;

use Illuminate\Support\Str;

/**
 * Agora RTC as the call transport. Agora needs no server-side call setup: we
 * mint a token for a channel name we invented and both devices join it, so
 * there is no outbound HTTP call in the ringing path.
 */
class AgoraProvider implements CallProvider
{
    public function name(): string
    {
        return 'agora';
    }

    public function configured(): bool
    {
        return $this->appId() !== '' && $this->appCertificate() !== '';
    }

    public function newChannel(): string
    {
        // 32 random chars — Agora channel names allow up to 64 and the value
        // is the only thing standing between an outsider and the audio.
        return 'sk-'.Str::random(32);
    }

    public function credentials(string $channel, int $uid): CallCredentials
    {
        $ttl = (int) config('calling.token_ttl', 3600);

        return new CallCredentials(
            provider: $this->name(),
            appId: $this->appId(),
            channel: $channel,
            uid: $uid,
            token: AgoraAccessToken::rtc($this->appId(), $this->appCertificate(), $channel, $uid, $ttl),
            expiresIn: $ttl,
        );
    }

    private function appId(): string
    {
        return (string) config('calling.agora.app_id');
    }

    private function appCertificate(): string
    {
        return (string) config('calling.agora.app_certificate');
    }
}
