<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Simple key-value store for admin-tunable app settings.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    /**
     * Cached in place of a setting that has no row, so one left at its
     * default is cached too instead of costing a query on every read.
     */
    private const UNSET = '__setting_unset__';

    protected $fillable = ['key', 'value'];

    private static function cacheKey(string $key): string
    {
        return "setting:{$key}";
    }

    /**
     * Read a raw setting value, falling back to $default when unset.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        // Memoized for the request (Octane forgets it between requests), so
        // a flag read once per job on a page costs one cache lookup, not 15.
        $value = Cache::memo()->rememberForever(
            self::cacheKey($key),
            fn () => static::query()->where('key', $key)->value('value') ?? self::UNSET,
        );

        return $value === self::UNSET ? $default : ($value ?? $default);
    }

    /**
     * Read a setting as a boolean flag.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');

        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Read a setting as an integer.
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Persist a setting value and bust its cache.
     */
    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::memo()->forget(self::cacheKey($key));
    }
}
