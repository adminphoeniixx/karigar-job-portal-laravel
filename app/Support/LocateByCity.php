<?php

namespace App\Support;

use App\Models\EmployerProfile;
use App\Models\WorkerProfile;
use App\Services\Geocoder;
use Throwable;

/**
 * Puts karigars and employers who gave a city but no map pin on the map at
 * their city's centre. Without a pin a karigar has no distance on the Find
 * Workers card, sorts last under "nearest" and falls outside every radius
 * filter; an employer without one has no point to measure from.
 *
 * Nominatim allows about one request a second, so each city is looked up
 * once (cached by {@see Geocoder::cityCentre()}) and the lookups are spaced.
 */
class LocateByCity
{
    /**
     * @return array{workers: int, employers: int, cities: int, unmatched: list<string>, synced: int|null}
     */
    public static function run(Geocoder $geocoder, bool $sync = false): array
    {
        $workers = WorkerProfile::whereNull('latitude')->whereNotNull('city')->where('city', '!=', '');
        $employers = EmployerProfile::whereNull('latitude')->whereNotNull('city')->where('city', '!=', '');

        $cities = (clone $workers)->select('city', 'state')->distinct()->get()
            ->concat((clone $employers)->select('city', 'state')->distinct()->get())
            ->map(fn ($row) => ['city' => (string) $row->city, 'state' => (string) $row->state])
            ->unique(fn (array $c) => mb_strtolower($c['city'].'|'.$c['state']))
            ->values();

        $counts = ['workers' => 0, 'employers' => 0, 'cities' => 0, 'unmatched' => [], 'synced' => null];
        $located = [];

        foreach ($cities as $c) {
            $cached = $geocoder->cityCentre($c['city'], $c['state'], lookup: false);
            $pin = $cached ?? $geocoder->cityCentre($c['city'], $c['state']);

            if ($cached === null) {
                usleep(1_100_000);
            }

            if ($pin === null) {
                $counts['unmatched'][] = trim($c['city'].', '.$c['state'], ', ');

                continue;
            }

            $counts['cities']++;
            $coords = ['latitude' => $pin[0], 'longitude' => $pin[1]];
            // A blank state is stored as null on some rows and '' on others.
            $inCity = fn ($q) => $q->where('city', $c['city'])->where(fn ($q) => $c['state'] === ''
                ? $q->whereNull('state')->orWhere('state', '')
                : $q->where('state', $c['state']));

            // Query-builder updates: no model events, so no search sync per row.
            $ids = (clone $workers)->where($inCity)->pluck('id');
            $counts['workers'] += WorkerProfile::whereKey($ids)->update($coords);
            $located = [...$located, ...$ids->all()];

            $counts['employers'] += (clone $employers)->where($inCity)->update($coords);
        }

        if ($sync && $located !== []) {
            $counts['synced'] = self::syncSearch($located);
        }

        return $counts;
    }

    /**
     * Push the newly placed karigars to search, where "nearest" and the
     * radius filter read the location. Real sign-ups only, like
     * {@see WageConversion::syncSearch()}.
     *
     * @param  list<int>  $ids
     */
    private static function syncSearch(array $ids): ?int
    {
        try {
            $profiles = WorkerProfile::whereKey($ids)
                ->whereHas('user', fn ($q) => $q
                    ->where('email', 'not like', '%@dummy.karigar.test')
                    ->where('email', 'not like', '%@karigar.test'))
                ->with('user')
                ->get()
                ->filter->shouldBeSearchable();

            $profiles->searchable();

            return $profiles->count();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
