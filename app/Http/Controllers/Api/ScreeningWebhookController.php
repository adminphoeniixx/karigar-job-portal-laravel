<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InterviewReminderCall;
use App\Models\ScreeningCall;
use App\Models\VerificationCall;
use App\Services\Screening\CallRecordings;
use App\Services\Screening\InterviewReminders;
use App\Services\Screening\ScreeningService;
use App\Services\Verification\VerificationCalls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where the voice provider reports how a screening call went.
 *
 * Unauthenticated by necessity — the provider has no session — so the shared
 * secret is the only thing standing between this endpoint and someone forging
 * transcripts or booking interviews. It is required, not optional: with no
 * secret configured the endpoint refuses every request rather than running
 * open.
 */
class ScreeningWebhookController extends Controller
{
    public function __invoke(Request $request, ScreeningService $screening, VerificationCalls $verificationCalls, InterviewReminders $reminders): JsonResponse
    {
        if ($refusal = $this->refuse($request)) {
            return $refusal;
        }

        $signature = (string) $request->header('X-Screening-Signature', '');
        $payload = $request->all();

        if (! $screening->agent()->verifyWebhook($payload, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $result = $screening->agent()->parseWebhook($payload);

        // The KYC-explaining calls ride the same agent and report here too.
        $verification = VerificationCall::where('provider_call_id', $result->providerCallId)->first();

        if ($verification !== null) {
            $verificationCalls->apply($verification, $result, $payload);

            return response()->json(['handled' => true]);
        }

        // So do the interview-day reminder calls.
        $reminder = InterviewReminderCall::where('provider_call_id', $result->providerCallId)->first();

        if ($reminder !== null) {
            $reminders->apply($reminder, $result, $payload);

            return response()->json(['handled' => true]);
        }

        $call = $screening->apply($result);

        // A callback for a call we do not know about is still a 200 — providers
        // retry non-2xx forever, and there is nothing here to fix.
        return response()->json(['handled' => $call !== null]);
    }

    /**
     * The call's audio, uploaded by the agent after the result. A separate
     * request so a slow or failed upload can never cost the call its outcome.
     */
    public function recording(Request $request, CallRecordings $recordings): JsonResponse
    {
        if ($refusal = $this->refuse($request)) {
            return $refusal;
        }

        // Hand-validated: outside a JSON request, validate() would answer a
        // bad upload with a redirect the agent cannot follow.
        $file = $request->file('recording');

        if ($file === null || ! $file->isValid() || $file->getSize() > 25 * 1024 * 1024) {
            return response()->json(['message' => 'A recording file of up to 25 MB is required.'], 422);
        }

        $call = ScreeningCall::where('provider_call_id', (string) $request->input('call_id'))->first();

        // Verification calls are not kept as audio: the transcript is enough
        // for the admin, and there is no employer to play it to.
        if ($call === null && VerificationCall::where('provider_call_id', (string) $request->input('call_id'))->exists()) {
            return response()->json(['handled' => false]);
        }

        // Nor are the one-minute interview reminders.
        if ($call === null && InterviewReminderCall::where('provider_call_id', (string) $request->input('call_id'))->exists()) {
            return response()->json(['handled' => false]);
        }

        if ($call === null) {
            Log::warning('Screening recording for an unknown call.', ['provider_call_id' => $request->input('call_id')]);

            return response()->json(['handled' => false]);
        }

        $recordings->put($call, $file);

        return response()->json(['handled' => true]);
    }

    /**
     * The shared-secret check both endpoints start with; null means go ahead.
     */
    private function refuse(Request $request): ?JsonResponse
    {
        $secret = (string) config('screening.webhook_secret');

        if ($secret === '') {
            Log::error('Screening webhook hit with no SCREENING_WEBHOOK_SECRET configured.');

            return response()->json(['message' => 'Webhook not configured.'], 503);
        }

        if (! hash_equals($secret, (string) $request->header('X-Screening-Signature', ''))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return null;
    }
}
