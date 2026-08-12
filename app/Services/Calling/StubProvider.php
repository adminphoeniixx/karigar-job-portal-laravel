<?php

namespace App\Services\Calling;

use Illuminate\Support\Str;

/**
 * A provider that issues credentials no SDK can use. It exists so the parts of
 * calling this app actually owns — permission checks, the ringing push, the
 * call log — can run locally and in tests without an Agora account.
 *
 * Always reports itself as configured; the audio simply never connects.
 *
 * Named "stub" rather than "null" on purpose: env('CALL_PROVIDER') would turn
 * the string "null" into an actual null and quietly select Agora instead.
 */
class StubProvider implements CallProvider
{
    public function name(): string
    {
        return 'stub';
    }

    public function configured(): bool
    {
        return true;
    }

    public function newChannel(): string
    {
        return 'stub-'.Str::random(32);
    }

    public function credentials(string $channel, int $uid): CallCredentials
    {
        return new CallCredentials(
            provider: $this->name(),
            appId: 'stub-app',
            channel: $channel,
            uid: $uid,
            token: 'stub-token-'.$uid,
            expiresIn: (int) config('calling.token_ttl', 3600),
        );
    }
}
