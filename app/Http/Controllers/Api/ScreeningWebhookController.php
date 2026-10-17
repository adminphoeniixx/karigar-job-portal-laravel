<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScreeningCall;
use App\Services\Screening\CallRecordings;
use App\Services\Screening\ScreeningService;
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
    public function __invoke(Request $request, ScreeningService $screening): JsonResponse
    {
        if ($refusal = $this->refuse($request)) {
            return $refusal;
        }

        $signature = (string) $request->header('X-Screening-Signature', '');
        $payload = $request->all();

        if (! $screening->agent()->verifyWebhook($payload, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $call = $screening->apply($screening->agent()->parseWebhook($payload));

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
