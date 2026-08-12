<?php

namespace App\Services\Screening;

use App\Enums\ScreeningCallStatus;
use App\Enums\ScreeningOutcome;
use App\Models\ScreeningCall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Dials nobody. Records what would have been said and returns a fake call id,
 * so the whole flow — trigger, script, retries, webhook, interview booking —
 * can be exercised before a telephony account exists.
 *
 * This is the default provider on purpose: shipping the feature without
 * credentials must be a no-op, never a wave of calls to real workers.
 */
class StubVoiceAgent implements VoiceAgent
{
    public function name(): string
    {
        return 'stub';
    }

    public function configured(): bool
    {
        return true;
    }

    public function place(ScreeningCall $call, CallScript $script, string $toPhone): string
    {
        Log::info('Screening call not dialled — stub voice agent in use.', [
            'screening_call_id' => $call->id,
            'to' => Str::mask($toPhone, '*', 2, 6),
            'language' => $script->language,
            'greeting' => $script->greeting,
        ]);

        return 'stub-'.Str::uuid()->toString();
    }

    public function verifyWebhook(array $payload, string $signature): bool
    {
        return true;
    }

    public function parseWebhook(array $payload): ScreeningResult
    {
        $status = ScreeningCallStatus::tryFrom((string) ($payload['status'] ?? '')) ?? ScreeningCallStatus::Completed;
        $outcome = ScreeningOutcome::tryFrom((string) ($payload['outcome'] ?? ''));
        $slot = $payload['proposed_interview_at'] ?? null;

        return new ScreeningResult(
            providerCallId: (string) ($payload['call_id'] ?? ''),
            status: $status,
            outcome: $outcome,
            proposedInterviewAt: $slot !== null ? Carbon::parse((string) $slot) : null,
            proposedMode: $payload['proposed_mode'] ?? null,
            summary: $payload['summary'] ?? null,
            transcript: $payload['transcript'] ?? null,
            durationSeconds: isset($payload['duration_seconds']) ? (int) $payload['duration_seconds'] : null,
            failureReason: $payload['failure_reason'] ?? null,
        );
    }
}
