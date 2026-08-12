<?php

namespace App\Services\Screening;

use App\Enums\ScreeningCallStatus;
use App\Enums\ScreeningOutcome;
use Illuminate\Support\Carbon;

/**
 * One provider's call-result callback, normalised. Every VoiceAgent turns its
 * own webhook shape into this so ScreeningService never learns which telephony
 * vendor is on the other end.
 */
final class ScreeningResult
{
    public function __construct(
        public readonly string $providerCallId,
        public readonly ScreeningCallStatus $status,
        public readonly ?ScreeningOutcome $outcome = null,
        public readonly ?Carbon $proposedInterviewAt = null,
        public readonly ?string $proposedMode = null,
        public readonly ?string $summary = null,
        public readonly ?string $transcript = null,
        public readonly ?int $durationSeconds = null,
        public readonly ?string $failureReason = null,
    ) {}
}
