<?php

namespace App\Support;

use App\Enums\KycStatus;
use App\Models\Setting;
use App\Models\User;

/**
 * Karigars must be verified before they apply to a job. Browsing, saving
 * jobs and calling an employer whose job offers a phone number stay open;
 * only the application waits for the KYC.
 *
 * Admins can lift the rule (Settings → "Karigars must be verified to apply"),
 * and it lifts itself while karigar verification is switched off.
 */
class WorkerVerification
{
    public const KEY = 'worker_verification_required';

    public static function required(): bool
    {
        return Verification::forWorkers()
            && Setting::bool(self::KEY, (bool) config('services.kyc.worker_required', true));
    }

    /**
     * not_submitted, pending, rejected or verified.
     */
    public static function status(User $worker): string
    {
        return $worker->kyc?->status->value ?? 'not_submitted';
    }

    /**
     * Why this karigar may not apply yet, or null when they may.
     */
    public static function blockMessage(User $worker): ?string
    {
        if (! self::required()) {
            return null;
        }

        return match (self::status($worker)) {
            KycStatus::Verified->value => null,
            KycStatus::Pending->value => __('Your KYC is under review. You can apply to jobs once it is approved.'),
            KycStatus::Rejected->value => __('Your KYC was not approved. Fix your documents and submit again to apply to jobs.'),
            default => __('Complete your KYC (Aadhaar + PAN) to apply to jobs. It takes two minutes.'),
        };
    }

    /**
     * The block the worker app and web read to show the right nudge.
     *
     * @return array{required: bool, status: string, can_apply: bool, message: ?string}
     */
    public static function summary(User $worker): array
    {
        $message = self::blockMessage($worker);

        return [
            'required' => self::required(),
            'status' => self::status($worker),
            'can_apply' => $message === null,
            'message' => $message,
        ];
    }
}
