<?php

namespace App\Services\Screening;

use App\Models\ScreeningCall;

/**
 * The telephony + speech stack that actually holds the screening conversation,
 * behind an interface so the vendor can change without touching the flow that
 * decides who gets called and what happens to the answer.
 *
 * An implementation only has two jobs: dial a number with a script, and turn
 * that vendor's result callback into a ScreeningResult.
 */
interface VoiceAgent
{
    /**
     * Short identifier stored on every call row ("sarvam", "stub").
     */
    public function name(): string;

    /**
     * Whether credentials and a caller-ID number are present. When false,
     * nothing is dialled — see ScreeningService::start().
     */
    public function configured(): bool;

    /**
     * Dial `$toPhone` and hand the agent its script. Returns the provider's own
     * call id, which is how its webhook is matched back to the row.
     *
     * @throws \Throwable when the provider refuses or is unreachable
     */
    public function place(ScreeningCall $call, CallScript $script, string $toPhone): string;

    /**
     * Verify a webhook came from this provider. A forged one could book
     * interviews and invent transcripts, so an unverified payload is dropped.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyWebhook(array $payload, string $signature): bool;

    /**
     * Normalise this provider's result callback.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): ScreeningResult;
}
