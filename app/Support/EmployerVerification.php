<?php

namespace App\Support;

use App\Enums\KycStatus;
use App\Models\Setting;
use App\Models\User;

/**
 * Employers must be verified before a job goes live. Saving drafts, browsing
 * workers and everything else stays open; only publishing waits for an admin
 * to approve the business documents.
 *
 * Admins can lift the rule (Settings → "Employers must be verified to post"),
 * and it lifts itself while the whole verification feature is switched off.
 */
class EmployerVerification
{
    public static function required(): bool
    {
        return Setting::bool('kyc_verification_enabled', true)
            && Setting::bool('employer_verification_required', (bool) config('services.kyc.employer_required', true));
    }

    /**
     * not_submitted, pending, rejected or verified.
     */
    public static function status(User $account): string
    {
        return $account->kyc?->status->value ?? 'not_submitted';
    }

    /**
     * Why this account may not publish a job yet, or null when it may.
     */
    public static function blockMessage(User $account): ?string
    {
        if (! self::required()) {
            return null;
        }

        return match (self::status($account)) {
            KycStatus::Verified->value => null,
            KycStatus::Pending->value => __('Your business verification is under review. You can post jobs once it is approved — save this one as a draft for now.'),
            KycStatus::Rejected->value => __('Your business verification was not approved. Fix the documents and submit again to post jobs.'),
            default => __('Verify your business (PAN / GST) to post jobs. You can save this one as a draft meanwhile.'),
        };
    }

    /**
     * The block the employer app and web read to show the right nudge.
     *
     * @return array{required: bool, status: string, can_post_jobs: bool, message: ?string}
     */
    public static function summary(User $account): array
    {
        $message = self::blockMessage($account);

        return [
            'required' => self::required(),
            'status' => self::status($account),
            'can_post_jobs' => $message === null,
            'message' => $message,
        ];
    }
}
