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
 * @property list<string>|null $skills
 * @property list<string>|null $job_titles
 */
class Category extends Model
{
    /** Forgotten by Admin\CategoryController whenever a category changes. */
    public const CACHE_KEY = 'categories.active';

    /** Cache key of {@see cachedSkillsMap()}; forgotten with CACHE_KEY. */
    public const SKILLS_CACHE_KEY = 'categories.skills';

    /** Cache key of {@see cachedJobProfiles()}; forgotten with CACHE_KEY. */
    public const JOB_PROFILES_CACHE_KEY = 'categories.job_profiles';

    protected $fillable = ['name', 'slug', 'skills', 'job_titles', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'skills' => 'array', 'job_titles' => 'array'];
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

    /**
     * The skills jobs in each active category are posted with, keyed by
     * category name, for the job form's suggestions. Cached as a plain array
     * (cached models do not survive: see the app's cache config); the admin
     * controller forgets it with the name list.
     *
     * @return array<string, list<string>>
     */
    public static function cachedSkillsMap(): array
    {
        return Cache::rememberForever(self::SKILLS_CACHE_KEY, fn () => static::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['name', 'skills'])
            ->mapWithKeys(fn (Category $category) => [$category->name => array_values($category->skills ?? [])])
            ->all());
    }

    /**
     * Every job title ("job profile") of the active categories, with its
     * category, in category order: the job form's title dropdown. Cached as
     * plain arrays; the admin controller forgets it with the rest.
     *
     * @return list<array{title: string, category: string}>
     */
    public static function cachedJobProfiles(): array
    {
        return Cache::rememberForever(self::JOB_PROFILES_CACHE_KEY, fn () => static::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['name', 'job_titles'])
            ->flatMap(fn (Category $category) => collect($category->job_titles ?? [])
                ->map(fn (string $title) => ['title' => $title, 'category' => $category->name]))
            ->values()
            ->all());
    }

    /**
     * Forgets every cached list, after any category changes.
     */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::SKILLS_CACHE_KEY);
        Cache::forget(self::JOB_PROFILES_CACHE_KEY);
    }
}
