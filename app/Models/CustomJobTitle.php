<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A job title employers typed that no active category lists. Admin →
 * Categories shows these, so the good ones can join a category's job titles
 * (and from there the job form's title list); the rest get hidden for good.
 *
 * @property int $id
 * @property string $title as the first employer typed it
 * @property string $normalized lower case and trimmed: one row per title
 * @property Carbon|null $hidden_at
 */
class CustomJobTitle extends Model
{
    protected $fillable = ['title', 'normalized', 'hidden_at'];

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime'];
    }

    /**
     * The form two titles are compared in. It matches `lower(trim(title))`,
     * which {@see forAdmin()} uses to find the jobs.
     */
    public static function normalise(string $title): string
    {
        return mb_strtolower(trim($title));
    }

    /**
     * Notes a job's title when no active category lists it. A title seen
     * before keeps its row, so one an admin hid stays hidden.
     */
    public static function record(string $title): void
    {
        $normalized = self::normalise($title);

        if ($normalized === '' || self::listed()->has($normalized)) {
            return;
        }

        static::query()->insertOrIgnore([
            'title' => trim($title),
            'normalized' => $normalized,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * What Admin → Categories lists: the titles still on some job, with how
     * many employers and jobs use them and the category they were posted under
     * most. Most employers first, then most jobs, then the latest.
     *
     * @return list<array{id: int, title: string, employers: int, jobs: int, category: string|null, last_used: string}>
     */
    public static function forAdmin(int $limit = 50): array
    {
        $listed = self::listed();

        // A title an admin since typed into a category by hand drops out here.
        $rows = static::query()->whereNull('hidden_at')->get()
            ->reject(fn (self $row) => $listed->has($row->normalized))
            ->keyBy('normalized');

        if ($rows->isEmpty()) {
            return [];
        }

        $jobs = JobListing::query()
            ->select(['employer_id', 'category', 'created_at'])
            ->selectRaw('lower(trim(title)) as normalized_title')
            ->whereIn(DB::raw('lower(trim(title))'), $rows->keys()->all())
            ->get()
            ->groupBy('normalized_title');

        return $rows
            ->map(function (self $row) use ($jobs) {
                $used = $jobs->get($row->normalized);

                // Every job that used it is gone.
                if ($used === null) {
                    return null;
                }

                /** @var Carbon $last */
                $last = $used->max('created_at');

                return [
                    'id' => $row->id,
                    'title' => $row->title,
                    'employers' => $used->pluck('employer_id')->unique()->count(),
                    'jobs' => $used->count(),
                    'category' => $used->pluck('category')->filter()->countBy()->sortDesc()->keys()->first(),
                    'last_used' => $last->diffForHumans(),
                    'last_used_at' => $last->getTimestamp(),
                ];
            })
            ->filter()
            ->sortBy([['employers', 'desc'], ['jobs', 'desc'], ['last_used_at', 'desc']])
            ->take($limit)
            ->map(fn (array $row) => Arr::except($row, 'last_used_at'))
            ->values()
            ->all();
    }

    /**
     * The titles the job form already offers, normalised, as keys.
     *
     * @return Collection<string, int>
     */
    private static function listed(): Collection
    {
        return collect(Category::cachedJobProfiles())
            ->map(fn (array $profile) => self::normalise($profile['title']))
            ->flip();
    }
}
