<?php

namespace App\Services\Calling;

/**
 * The audio transport behind in-app calls, kept behind an interface so the
 * provider can be swapped (Agora today; Twilio/100ms/Zego later) without
 * touching CallService, the API or the call log.
 *
 * A provider only deals in channels and join tokens. Who is allowed to call
 * whom, ringing, and the call's lifecycle all live in CallService.
 */
interface CallProvider
{
    /**
     * Short identifier the mobile app switches its SDK on ("agora", "null").
     */
    public function name(): string;

    /**
     * Whether credentials are present. When false, CallService refuses to
     * start calls rather than handing out tokens nobody can use.
     */
    public function configured(): bool;

    /**
     * A fresh, unguessable, single-use channel name for one call.
     */
    public function newChannel(): string;

    /**
     * Join credentials for one participant on one channel.
     */
    public function credentials(string $channel, int $uid): CallCredentials;
}
