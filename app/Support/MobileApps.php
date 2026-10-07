<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * What the worker and employer apps ask the server before anything else: is
 * there a newer version (and must they install it), and is the service under
 * maintenance. Both are set in Admin → Settings → Mobile apps, so a forced
 * update or a maintenance window needs no deploy.
 */
class MobileApps
{
    public const APPS = ['worker', 'employer'];

    public const PLATFORMS = ['android', 'ios'];

    /** JSON: {app: {platform: {latest, min, store_url}}}. */
    public const VERSIONS_KEY = 'mobile_app_versions';

    public const UPDATE_MESSAGE_KEY = 'mobile_app_update_message';

    public const MAINTENANCE_KEY = 'mobile_maintenance_enabled';

    public const MAINTENANCE_MESSAGE_KEY = 'mobile_maintenance_message';

    public const MAINTENANCE_UNTIL_KEY = 'mobile_maintenance_until';

    /**
     * Every app and platform's version settings, with blanks where none is set.
     *
     * @return array<string, array<string, array{latest: ?string, min: ?string, store_url: ?string}>>
     */
    public static function versions(): array
    {
        $stored = json_decode((string) Setting::get(self::VERSIONS_KEY), true) ?: [];
        $versions = [];

        foreach (self::APPS as $app) {
            foreach (self::PLATFORMS as $platform) {
                $row = $stored[$app][$platform] ?? [];
                $versions[$app][$platform] = [
                    'latest' => filled($row['latest'] ?? null) ? (string) $row['latest'] : null,
                    'min' => filled($row['min'] ?? null) ? (string) $row['min'] : null,
                    'store_url' => filled($row['store_url'] ?? null) ? (string) $row['store_url'] : null,
                ];
            }
        }

        return $versions;
    }

    /**
     * @param  array<string, array<string, array<string, ?string>>>  $versions
     */
    public static function saveVersions(array $versions, ?string $message): void
    {
        Setting::set(self::VERSIONS_KEY, json_encode($versions));
        Setting::set(self::UPDATE_MESSAGE_KEY, trim((string) $message));
    }

    /**
     * The update check for one installed app. `force` means the installed
     * version is below the minimum: the app should block until updated.
     * Without the installed version only the published versions come back.
     *
     * @return array<string, mixed>
     */
    public static function update(string $app, string $platform, ?string $installed): array
    {
        $row = self::versions()[$app][$platform];
        $installed = $installed !== null ? self::clean($installed) : null;

        $available = $installed !== null && $row['latest'] !== null && version_compare($installed, $row['latest'], '<');
        $force = $installed !== null && $row['min'] !== null && version_compare($installed, $row['min'], '<');

        return [
            'app' => $app,
            'platform' => $platform,
            'installed_version' => $installed,
            'latest_version' => $row['latest'],
            'min_version' => $row['min'],
            'update_available' => $available || $force,
            'force_update' => $force,
            'store_url' => $row['store_url'],
            'message' => (string) Setting::get(self::UPDATE_MESSAGE_KEY) ?: null,
        ];
    }

    public static function underMaintenance(): bool
    {
        return Setting::bool(self::MAINTENANCE_KEY, false);
    }

    /**
     * @return array{maintenance: bool, message: ?string, until: ?string}
     */
    public static function maintenance(): array
    {
        $on = self::underMaintenance();
        $until = Setting::get(self::MAINTENANCE_UNTIL_KEY);

        return [
            'maintenance' => $on,
            'message' => $on ? (Setting::get(self::MAINTENANCE_MESSAGE_KEY) ?: __('We are improving Super Karigar. Please try again in a little while.')) : null,
            // Only while it is on, as ISO 8601: the app shows "back by …".
            'until' => $on && filled($until) ? Carbon::parse($until)->toIso8601String() : null,
        ];
    }

    public static function saveMaintenance(bool $on, ?string $message, ?string $until): void
    {
        Setting::set(self::MAINTENANCE_KEY, $on ? '1' : '0');
        Setting::set(self::MAINTENANCE_MESSAGE_KEY, trim((string) $message));
        Setting::set(self::MAINTENANCE_UNTIL_KEY, filled($until) ? Carbon::parse($until, config('app.display_timezone'))->utc()->toIso8601String() : '');
    }

    /**
     * "1.4.2+37" or "v1.4.2" as the comparable "1.4.2".
     */
    private static function clean(string $version): string
    {
        return preg_replace(['/^v/i', '/[+\- ].*$/'], '', trim($version));
    }
}
