<?php

namespace App\Support;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
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

    /**
     * Deliver a data-only, high-priority push — the kind that has to wake a
     * sleeping app rather than sit in the tray. Used for call ringing, where
     * the app itself draws the incoming-call UI (CallKit / ConnectionService)
     * and a notification payload would be both wrong and too slow.
     *
     * iOS caveat: this is still a normal FCM alert push. Apple's PushKit/VoIP
     * channel needs a direct APNs connection with a VoIP certificate, which
     * FCM does not offer — see docs/calling.md.
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, string|int|null>  $data
     * @param  int|null  $ttlSeconds  Drop the push if undelivered after this long.
     *                                A missed call is better than one that rings
     *                                ten minutes late.
     * @return array{sent: int, failed: int}
     */
    public function sendData(array $tokens, array $data, ?int $ttlSeconds = null): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));

        if ($tokens === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        try {
            $messaging = app(Messaging::class);
        } catch (Throwable $e) {
            Log::warning('FCM not configured, data push skipped: '.$e->getMessage());

            return ['sent' => 0, 'failed' => count($tokens)];
        }

        $stringData = [];
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $stringData[$key] = (string) $value;
            }
        }

        $android = ['priority' => 'high'];
        if ($ttlSeconds !== null) {
            $android['ttl'] = $ttlSeconds.'s';
        }

        $message = CloudMessage::new()
            ->withData($stringData)
            ->withAndroidConfig(AndroidConfig::fromArray($android))
            ->withApnsConfig(
                ApnsConfig::new()
                    ->withHeader('apns-push-type', 'alert')
                    ->withHeader('apns-priority', '10')
                    // Without content-available a data-only payload is dropped
                    // before the app ever sees it.
                    ->withApsField('content-available', 1)
            );

        $sent = 0;
        $failed = 0;
        $dead = [];

        foreach (array_chunk($tokens, 500) as $chunk) {
            try {
                $report = $messaging->sendMulticast($message, $chunk);
            } catch (Throwable $e) {
                Log::error('FCM data push failed: '.$e->getMessage());
                $failed += count($chunk);

                continue;
            }

            $sent += $report->successes()->count();
            $failed += $report->failures()->count();
            $dead = array_merge($dead, $report->invalidTokens(), $report->unknownTokens());
        }

        if ($dead !== []) {
            DeviceToken::whereIn('token', $dead)->delete();
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
