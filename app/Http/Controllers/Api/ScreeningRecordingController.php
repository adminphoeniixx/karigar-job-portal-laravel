<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScreeningCall;
use App\Services\Screening\CallRecordings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Plays an AI screening call's recording.
 *
 * Reached only through the signed link in the call's `recording_url`, which
 * is handed out to the employer who may see the call and runs out after
 * CallRecordings::LINK_MINUTES. No session or token here, because an audio
 * player (the web <audio> tag, a phone's media player) cannot be relied on to
 * send one.
 */
class ScreeningRecordingController extends Controller
{
    public function __invoke(ScreeningCall $call, CallRecordings $recordings): BinaryFileResponse
    {
        $path = $recordings->localCopy($call);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
