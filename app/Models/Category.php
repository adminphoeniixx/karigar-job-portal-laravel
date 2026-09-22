<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property int $sort_order
 */
class Category extends Model
{
    /** Forgotten by Admin\CategoryController whenever a category changes. */
    public const CACHE_KEY = 'categories.active';

    protected $fillable = ['name', 'slug', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Category $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /**
     * Active category names, cached — used to populate dropdowns everywhere.
     *
     * @return array<int, string>
     */
    public static function activeNames(): array
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * The same list, cached. This is read on every request that shares Inertia
     * props, so it must not be a query each time; the admin controller forgets
     * the key whenever a category changes.
     *
     * The key lives here rather than at each call site so there is one spelling
     * of it to forget.
     *
     * @return array<int, string>
     */
    public static function cachedActiveNames(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::activeNames());
    }
}
