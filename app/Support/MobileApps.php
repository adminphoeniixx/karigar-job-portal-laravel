<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
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

    /** JSON: {app: {update_message, maintenance, maintenance_message, maintenance_until}}. */
    public const APP_SETTINGS_KEY = 'mobile_app_settings';

    // One setting for both apps, from before they were split. Read only until
    // the per-app settings are first saved, so a deploy keeps the live state.
    private const LEGACY_UPDATE_MESSAGE_KEY = 'mobile_app_update_message';

    private const LEGACY_MAINTENANCE_KEY = 'mobile_maintenance_enabled';

    private const LEGACY_MAINTENANCE_MESSAGE_KEY = 'mobile_maintenance_message';

    private const LEGACY_MAINTENANCE_UNTIL_KEY = 'mobile_maintenance_until';

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
    public static function saveVersions(array $versions): void
    {
        Setting::set(self::VERSIONS_KEY, json_encode($versions));
    }

    /**
     * Each app's update message and maintenance window. `maintenance_until`
     * is UTC ISO 8601.
     *
     * @return array<string, array{update_message: ?string, maintenance: bool, maintenance_message: ?string, maintenance_until: ?string}>
     */
    public static function appSettings(): array
    {
        $stored = json_decode((string) Setting::get(self::APP_SETTINGS_KEY), true);

        if (! is_array($stored)) {
            $legacy = [
                'update_message' => Setting::get(self::LEGACY_UPDATE_MESSAGE_KEY),
                'maintenance' => Setting::bool(self::LEGACY_MAINTENANCE_KEY, false),
                'maintenance_message' => Setting::get(self::LEGACY_MAINTENANCE_MESSAGE_KEY),
                'maintenance_until' => Setting::get(self::LEGACY_MAINTENANCE_UNTIL_KEY),
            ];
            $stored = array_fill_keys(self::APPS, $legacy);
        }

        $settings = [];

        foreach (self::APPS as $app) {
            $row = $stored[$app] ?? [];
            $settings[$app] = [
                'update_message' => filled($row['update_message'] ?? null) ? (string) $row['update_message'] : null,
                'maintenance' => (bool) ($row['maintenance'] ?? false),
                'maintenance_message' => filled($row['maintenance_message'] ?? null) ? (string) $row['maintenance_message'] : null,
                'maintenance_until' => filled($row['maintenance_until'] ?? null) ? (string) $row['maintenance_until'] : null,
            ];
        }

        return $settings;
    }

    /**
     * @param  array<string, array<string, mixed>>  $settings  per app; `maintenance_until` in India time, as typed
     */
    public static function saveAppSettings(array $settings): void
    {
        $clean = [];

        foreach (self::APPS as $app) {
            $row = $settings[$app] ?? [];
            $until = $row['maintenance_until'] ?? null;
            $clean[$app] = [
                'update_message' => trim((string) ($row['update_message'] ?? '')) ?: null,
                'maintenance' => (bool) ($row['maintenance'] ?? false),
                'maintenance_message' => trim((string) ($row['maintenance_message'] ?? '')) ?: null,
                'maintenance_until' => filled($until) ? Carbon::parse($until, config('app.display_timezone'))->utc()->toIso8601String() : null,
            ];
        }

        Setting::set(self::APP_SETTINGS_KEY, json_encode($clean));
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
            'message' => self::appSettings()[$app]['update_message'],
        ];
    }

    public static function underMaintenance(string $app): bool
    {
        return self::appSettings()[$app]['maintenance'] ?? false;
    }

    /**
     * @return array{app: string, maintenance: bool, message: ?string, until: ?string}
     */
    public static function maintenance(string $app): array
    {
        $row = self::appSettings()[$app];
        $on = $row['maintenance'];

        return [
            'app' => $app,
            'maintenance' => $on,
            'message' => $on ? ($row['maintenance_message'] ?? __('We are improving Super Karigar. Please try again in a little while.')) : null,
            // Only while it is on, as ISO 8601: the app shows "back by …".
            'until' => $on && $row['maintenance_until'] !== null ? Carbon::parse($row['maintenance_until'])->toIso8601String() : null,
        ];
    }

    /**
     * Which app sent a request: the X-App header the apps send, else the
     * role being signed in with, else the signed-in user's role. Null when it
     * cannot be told (a guest call without the header).
     */
    public static function appFor(Request $request): ?string
    {
        foreach ([$request->header('X-App'), $request->input('role')] as $hint) {
            if (is_string($hint) && in_array(strtolower($hint), self::APPS, true)) {
                return strtolower($hint);
            }
        }

        $user = $request->user('sanctum');

        return match (true) {
            ! $user instanceof User => null,
            $user->isWorker() => 'worker',
            $user->isEmployer() => 'employer',
            default => null,
        };
    }

    /**
     * "1.4.2+37" or "v1.4.2" as the comparable "1.4.2".
     */
    private static function clean(string $version): string
    {
        return preg_replace(['/^v/i', '/[+\- ].*$/'], '', trim($version));
    }
}
