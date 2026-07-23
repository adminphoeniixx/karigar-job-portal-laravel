<?php

namespace App\Support;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Throwable;

/**
 * Thin wrapper around Firebase Cloud Messaging. Delivers a title/body/data
 * payload to a batch of device tokens and prunes tokens FCM reports as dead,
 * so the device_tokens table stays clean over time.
 */
class PushSender
{
    /**
     * Deliver a push to the given FCM registration tokens.
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, string|int|null>  $data  Optional deep-link data (e.g. ['url' => '/worker/applications']).
     * @return array{sent: int, failed: int}
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));

        if ($tokens === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        try {
            $messaging = app(Messaging::class);
        } catch (Throwable $e) {
            // Credentials missing/invalid — log and skip rather than crash the request.
            Log::warning('FCM not configured, push skipped: '.$e->getMessage());

            return ['sent' => 0, 'failed' => count($tokens)];
        }

        // FCM data payload values must all be strings.
        $stringData = [];
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $stringData[$key] = (string) $value;
            }
        }

        $message = CloudMessage::new()
            ->withNotification(FcmNotification::create($title, $body))
            ->withData($stringData);

        $sent = 0;
        $failed = 0;
        $dead = [];

        // FCM multicast accepts at most 500 tokens per call, so send in chunks.
        foreach (array_chunk($tokens, 500) as $chunk) {
            try {
                $report = $messaging->sendMulticast($message, $chunk);
            } catch (Throwable $e) {
                Log::error('FCM send failed: '.$e->getMessage());
                $failed += count($chunk);

                continue;
            }

            $sent += $report->successes()->count();
            $failed += $report->failures()->count();
            $dead = array_merge($dead, $report->invalidTokens(), $report->unknownTokens());
        }

        // Remove tokens FCM considers permanently invalid or unknown.
        if ($dead !== []) {
            DeviceToken::whereIn('token', $dead)->delete();
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
