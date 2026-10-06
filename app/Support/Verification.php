<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Whether KYC verification is switched on, from Admin → Settings. One master
 * switch covers everyone; a second turns it off for karigars alone, so
 * employers can keep verifying their business while workers are never asked.
 *
 * Off means gone, not optional: the KYC screens 404, the badge disappears and
 * the apps hide their prompts. Documents are kept for when it comes back.
 */
final class Verification
{
    public const KEY = 'kyc_verification_enabled';

    public const WORKER_KEY = 'worker_verification_enabled';

    public static function enabled(): bool
    {
        return Setting::bool(self::KEY, true);
    }

    public static function forWorkers(): bool
    {
        return self::enabled() && Setting::bool(self::WORKER_KEY, true);
    }

    /**
     * The switch that applies to this user: the worker one for a karigar, the
     * master one for everyone else (and for a guest).
     */
    public static function enabledFor(?User $user): bool
    {
        return $user?->isWorker() ? self::forWorkers() : self::enabled();
    }
}
