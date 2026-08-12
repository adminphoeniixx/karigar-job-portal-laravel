<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $secret = (string) config('screening.webhook_secret');

        if ($secret === '') {
            Log::error('Screening webhook hit with no SCREENING_WEBHOOK_SECRET configured.');

            return response()->json(['message' => 'Webhook not configured.'], 503);
        }

        $signature = (string) $request->header('X-Screening-Signature', '');

        if (! hash_equals($secret, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->all();

        if (! $screening->agent()->verifyWebhook($payload, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $call = $screening->apply($screening->agent()->parseWebhook($payload));

        // A callback for a call we do not know about is still a 200 — providers
        // retry non-2xx forever, and there is nothing here to fix.
        return response()->json(['handled' => $call !== null]);
    }
}
