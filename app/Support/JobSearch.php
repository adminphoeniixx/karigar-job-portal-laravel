<?php

namespace App\Support;

use App\Models\JobListing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Shared Typesense-backed job search used by both the public browse page
 * and the in-app worker jobs page, so the filter logic lives in one place.
 */
class JobSearch
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, JobListing>
     */
    public static function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $filterBy = self::buildFilterBy($filters);

        $search = JobListing::search(trim((string) ($filters['q'] ?? '')) ?: '*')
            ->query(fn ($query) => $query->with('employer:id,name'));

        if ($filterBy !== '') {
            $search->options(['filter_by' => $filterBy]);
        }

        return $search->paginate($perPage)->withQueryString();
    }

    /**
     * Build a Typesense `filter_by` expression from the request filters.
     *
     * @param  array<string, mixed>  $filters
     */
    private static function buildFilterBy(array $filters): string
    {
        $parts = [];

        foreach (['state', 'city', 'category'] as $field) {
            if (! empty($filters[$field])) {
                $parts[] = "{$field}:=".self::quote($filters[$field]);
            }
        }

        if (! empty($filters['skill'])) {
            $parts[] = 'skills:='.self::quote($filters['skill']);
        }

        if (isset($filters['lat'], $filters['lng'], $filters['radius'])) {
            $parts[] = sprintf('location:(%F, %F, %F km)', $filters['lat'], $filters['lng'], $filters['radius']);
        }

        return implode(' && ', $parts);
    }

    private static function quote(string $value): string
    {
        return '`'.str_replace('`', '', $value).'`';
    }
}
