<?php

namespace App\Services\Screening;

use App\Enums\ScreeningCallStatus;
use App\Enums\ScreeningOutcome;
use App\Models\ScreeningCall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * LiveKit as the screening-call stack: agent runtime, speech services and the
 * SIP bridge that reaches a real phone.
 *
 * Placing a call is one request: dispatch our agent into a fresh room with the
 * script and the number as metadata. The agent then dials the worker itself and
 * waits for them to join before speaking.
 *
 * Dialling from here instead would race — the worker could answer into a room
 * the agent has not finished joining and hear silence. LiveKit documents the
 * agent-driven order for exactly this reason.
 *
 * LiveKit does not sell the number. `sip_trunk_id` points at a trunk configured
 * against an Indian carrier, which holds the +91 number and the DLT
 * registration; changing carriers is a trunk id, not a code change.
 *
 * The result callback comes from our own agent service rather than from
 * LiveKit, so the payload shape here is ours and stays stable across vendors.
 */
class LiveKitVoiceAgent implements VoiceAgent
{
    /** Rooms we created. Anything else on the webhook is not ours. */
    private const ROOM_PREFIX = 'screening-';

    public function name(): string
    {
        return 'livekit';
    }

    public function configured(): bool
    {
        return $this->url() !== ''
            && $this->apiKey() !== ''
            && $this->apiSecret() !== ''
            && $this->trunkId() !== '';
    }

    public function place(ScreeningCall $call, CallScript $script, string $toPhone): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('LiveKit is not configured.');
        }

        $room = self::ROOM_PREFIX.$call->id.'-'.Str::lower(Str::random(12));

        // Everything the agent needs: who to ring, what to say, and how to
        // report back. It never queries our database, so the only worker data
        // that leaves this class is the number it has to dial.
        $metadata = json_encode([
            'screening_call_id' => $call->id,
            'room' => $room,
            'dial' => [
                'sip_trunk_id' => $this->trunkId(),
                'sip_call_to' => $toPhone,
                'participant_identity' => 'worker',
                // Shown on the handset where the carrier passes it through.
                'participant_name' => (string) config('screening.brand', 'Super Karigar'),
                // Workers answer on site — hammers, drills, traffic. Without
                // noise cancellation the transcription is guesswork.
                'krisp_enabled' => true,
                'wait_until_answered' => true,
                'play_dialtone' => false,
            ],
            'language' => $script->language,
            'greeting' => $script->greeting,
            'instructions' => $script->instructions,
            'extraction_schema' => CallScript::extractionSchema(),
            'voice' => (string) config('screening.voice'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->rpc('AgentDispatchService/CreateDispatch', [
            'agent_name' => (string) config('screening.livekit.agent_name', 'screening-agent'),
            'room' => $room,
            'metadata' => $metadata,
        ]);

        return $room;
    }

    /**
     * The shared secret is already checked, in constant time, by the webhook
     * controller. What is left to confirm is that the payload names a room this
     * class created — a valid secret should still not let a caller invent call
     * ids for rows belonging to some other provider.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyWebhook(array $payload, string $signature): bool
    {
        return str_starts_with((string) ($payload['call_id'] ?? ''), self::ROOM_PREFIX);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): ScreeningResult
    {
        $slot = $payload['proposed_interview_at'] ?? null;

        return new ScreeningResult(
            providerCallId: (string) ($payload['call_id'] ?? ''),
            status: ScreeningCallStatus::tryFrom((string) ($payload['status'] ?? '')) ?? ScreeningCallStatus::Completed,
            outcome: ScreeningOutcome::tryFrom((string) ($payload['outcome'] ?? '')),
            // The agent reports IST; a bare local time would otherwise be read
            // as UTC and book the interview five and a half hours early.
            proposedInterviewAt: filled($slot)
                ? Carbon::parse((string) $slot, config('screening.window.timezone', 'Asia/Kolkata'))
                : null,
            proposedMode: $payload['proposed_mode'] ?? null,
            summary: $payload['summary'] ?? null,
            transcript: $payload['transcript'] ?? null,
            durationSeconds: isset($payload['duration_seconds']) ? (int) $payload['duration_seconds'] : null,
            failureReason: $payload['failure_reason'] ?? null,
        );
    }

    /**
     * One LiveKit Twirp call. Twirp reports failures as non-2xx with a JSON
     * body, so a throw here carries the provider's own message into the log
     * ScreeningService writes when a dial fails.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function rpc(string $service, array $body): array
    {
        $response = Http::withToken($this->token())
            ->timeout((int) config('screening.livekit.timeout', 20))
            ->asJson()
            ->post($this->restUrl().'/twirp/livekit.'.$service, $body);

        if ($response->failed()) {
            throw new RuntimeException(
                'LiveKit '.$service.' failed ('.$response->status().'): '.$response->body()
            );
        }

        return (array) $response->json();
    }

    /**
     * A short-lived server JWT. LiveKit's server APIs are HS256-signed with the
     * project secret — the same shape as any JWT, so it is minted here rather
     * than pulling in a library for twenty lines of base64.
     */
    private function token(): string
    {
        $now = time();

        $claims = [
            'iss' => $this->apiKey(),
            'sub' => $this->apiKey(),
            'iat' => $now,
            'exp' => $now + 60,
            'video' => [
                'roomCreate' => true,
                'roomAdmin' => true,
                'agent' => true,
                // Required for the SIP endpoints; without it CreateSIPParticipant
                // returns a permission error rather than dialling.
                'sip' => ['admin' => true],
            ],
        ];

        $segments = [
            $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR)),
        ];

        $signing = implode('.', $segments);
        $segments[] = $this->base64Url(hash_hmac('sha256', $signing, $this->apiSecret(), true));

        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * The REST host. Config holds the websocket URL the SDKs want; the server
     * API lives on the same host over https.
     */
    private function restUrl(): string
    {
        return rtrim(str_replace(['wss://', 'ws://'], ['https://', 'http://'], $this->url()), '/');
    }

    private function url(): string
    {
        return (string) config('screening.livekit.url');
    }

    private function apiKey(): string
    {
        return (string) config('screening.livekit.api_key');
    }

    private function apiSecret(): string
    {
        return (string) config('screening.livekit.api_secret');
    }

    private function trunkId(): string
    {
        return (string) config('screening.livekit.sip_trunk_id');
    }
}
