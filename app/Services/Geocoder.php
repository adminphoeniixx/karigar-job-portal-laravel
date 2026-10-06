<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Finds the map pin for a job from its address, the way the web job form does
 * in the browser (OpenStreetMap's Nominatim), for clients that send an address
 * but no coordinates: the employer app. A typed street address is the precise
 * answer; city and state alone put the pin on the city centre.
 */
class Geocoder
{
    /**
     * @return array{0: float, 1: float}|null [latitude, longitude], or null when
     *                                        switched off, nothing matched, or the service is unreachable
     */
    public function locate(?string $address, ?string $city, ?string $state): ?array
    {
        if (! config('services.geocoder.enabled')) {
            return null;
        }

        $address = trim((string) $address);
        $city = trim((string) $city);
        $state = trim((string) $state);

        if ($address === '' && $city === '') {
            return null;
        }

        $query = $address !== ''
            ? ['q' => implode(', ', array_filter([$address, $city, $state, 'India']))]
            : array_filter(['city' => $city, 'state' => $state, 'country' => 'India']);

        try {
            $response = Http::timeout(5)
                // Nominatim's usage policy asks every client to name itself.
                ->withHeaders(['User-Agent' => config('app.name').' job-location ('.config('app.url').')'])
                ->get(config('services.geocoder.url'), $query + ['format' => 'json', 'limit' => 1, 'countrycodes' => 'in']);

            $hit = $response->successful() ? ($response->json()[0] ?? null) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! is_array($hit) || ! isset($hit['lat'], $hit['lon'])) {
            return null;
        }

        return [(float) $hit['lat'], (float) $hit['lon']];
    }

    /**
     * The centre of a city, for a karigar or employer who gave a city but no
     * map pin: it puts them on the map for "3.2 km away" and nearest-first
     * sorting. Cached for good, a city does not move. With `$lookup` false
     * only the cache is read, so a page listing many people never waits on
     * the geocoder (see the `karigars:locate` command, which fills it).
     *
     * @return array{0: float, 1: float}|null [latitude, longitude]
     */
    public function cityCentre(?string $city, ?string $state, bool $lookup = true): ?array
    {
        $city = trim((string) $city);
        $state = trim((string) $state);

        if ($city === '') {
            return null;
        }

        $key = 'geocoder:city:'.md5(mb_strtolower($city.'|'.$state));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached === [] ? null : [(float) $cached[0], (float) $cached[1]];
        }

        if (! $lookup) {
            return null;
        }

        $pin = $this->locate(null, $city, $state);

        // A state typed wrong ("Chandigarh, Punjab") still finds the city.
        if ($pin === null && $state !== '') {
            usleep(1_100_000);
            $pin = $this->locate(null, $city, null);
        }

        // A city that matched nothing is not asked about again for a week.
        Cache::put($key, $pin ?? [], $pin ? now()->addYears(5) : now()->addWeek());

        return $pin;
    }

    /**
     * Places matching what a person typed ("Sanganer, Jaipur"), for a
     * location picker. Cached for a month: Nominatim allows about one request
     * a second and asks that the same query is not sent twice.
     *
     * @return list<array{label: string, city: string|null, state: string|null, latitude: float, longitude: float}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');

        if (! config('services.geocoder.enabled') || mb_strlen($query) < 2) {
            return [];
        }

        $key = 'geocoder:search:'.md5(mb_strtolower($query).'|'.$limit);

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $hits = $this->request(config('services.geocoder.url'), [
            'q' => $query,
            'format' => 'json',
            'addressdetails' => 1,
            'limit' => $limit,
            'countrycodes' => 'in',
        ]);

        // Unreachable: say nothing, and ask again next time.
        if (! is_array($hits)) {
            return [];
        }

        $places = collect($hits)
            ->filter(fn ($hit) => is_array($hit) && isset($hit['lat'], $hit['lon']))
            ->map(fn (array $hit) => $this->place($hit))
            ->unique('label')
            ->values()
            ->all();

        Cache::put($key, $places, now()->addDays(30));

        return $places;
    }

    /**
     * The place at a map pin, for naming a point the person dropped.
     *
     * @return array{label: string, city: string|null, state: string|null, latitude: float, longitude: float}|null
     */
    public function reverse(float $latitude, float $longitude): ?array
    {
        if (! config('services.geocoder.enabled')) {
            return null;
        }

        $key = 'geocoder:reverse:'.round($latitude, 4).','.round($longitude, 4);

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $hit = $this->request(str_replace('/search', '/reverse', (string) config('services.geocoder.url')), [
            'lat' => $latitude,
            'lon' => $longitude,
            'format' => 'json',
            'addressdetails' => 1,
            'zoom' => 14,
        ]);

        if (! is_array($hit) || ! isset($hit['lat'], $hit['lon'])) {
            return null;
        }

        $place = ['latitude' => $latitude, 'longitude' => $longitude] + $this->place($hit);
        Cache::put($key, $place, now()->addDays(30));

        return $place;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function request(string $url, array $query): mixed
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => config('app.name').' job-location ('.config('app.url').')'])
                ->get($url, $query);

            return $response->successful() ? $response->json() : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * A short name a person recognises ("Sanganer, Jaipur, Rajasthan")
     * rather than Nominatim's full postal line.
     *
     * @param  array<string, mixed>  $hit
     * @return array{label: string, city: string|null, state: string|null, latitude: float, longitude: float}
     */
    private function place(array $hit): array
    {
        $address = is_array($hit['address'] ?? null) ? $hit['address'] : [];
        $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['county'] ?? $address['state_district'] ?? null;
        $state = $address['state'] ?? null;
        $area = $address['suburb'] ?? $address['neighbourhood'] ?? $address['hamlet'] ?? null;

        $label = collect([$area, $city, $state])->filter()->unique()->join(', ');

        if ($label === '') {
            $label = collect(explode(',', (string) ($hit['display_name'] ?? '')))->map(fn ($p) => trim($p))->filter()->take(3)->join(', ');
        }

        return [
            'label' => $label,
            'city' => $city,
            'state' => $state,
            'latitude' => (float) $hit['lat'],
            'longitude' => (float) $hit['lon'],
        ];
    }
}
