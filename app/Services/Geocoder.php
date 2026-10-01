<?php

namespace App\Services;

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
}
