<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's API token. Sanctum stamps `last_used_at` on every request it
 * authenticates, which made every app call a database write too. Device
 * sessions only show it as "2 minutes ago", so once a minute is plenty.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** Seconds between two `last_used_at` writes for the same token. */
    private const LAST_USED_RESOLUTION = 60;

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->onlyTouchesLastUsedRecently()) {
            return true;
        }

        return parent::save($options);
    }

    private function onlyTouchesLastUsedRecently(): bool
    {
        if (array_keys($this->getDirty()) !== ['last_used_at']) {
            return false;
        }

        $previous = $this->getOriginal('last_used_at');

        return $previous !== null && $previous->diffInSeconds(now(), absolute: true) < self::LAST_USED_RESOLUTION;
    }
}
