<?php

namespace App\Jobs;

use App\Models\CallSession;
use App\Services\Calling\CallService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Marks a call missed when nobody picked up.
 *
 * Dispatched with a delay of config('calling.ring_timeout') the moment a call
 * starts ringing. Harmless if the call was already answered, declined or
 * cancelled — CallService::expire() only acts on calls still ringing.
 *
 * This is a backstop, not the primary path: the Flutter side runs its own
 * ring timer so the UI gives up on time even if the queue is behind.
 */
class ExpireCallSession implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $callId) {}

    public function handle(CallService $calls): void
    {
        $call = CallSession::with('caller', 'callee')->find($this->callId);

        if ($call !== null) {
            $calls->expire($call);
        }
    }
}
