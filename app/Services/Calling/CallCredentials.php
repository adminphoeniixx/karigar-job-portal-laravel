<?php

namespace App\Services\Calling;

/**
 * Everything one device needs to join a call's audio channel. Issued per user
 * per call — the caller and the callee get different tokens for the same
 * channel, and neither token outlives the call by more than the configured TTL.
 */
final class CallCredentials
{
    public function __construct(
        public readonly string $provider,
        public readonly string $appId,
        public readonly string $channel,
        public readonly int $uid,
        public readonly string $token,
        public readonly int $expiresIn,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'app_id' => $this->appId,
            'channel' => $this->channel,
            'uid' => $this->uid,
            'token' => $this->token,
            'expires_in' => $this->expiresIn,
        ];
    }
}
