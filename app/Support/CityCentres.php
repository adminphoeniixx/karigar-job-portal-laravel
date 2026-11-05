<?php

namespace App\Support;

/**
 * The centre of every city and town in {@see IndianCities}, so a city typed
 * into the feed's place picker is found at once instead of after a round
 * trip to Nominatim: most karigars move their feed to a town, not a street.
 * Localities, and the few places listed without a centre, still go to
 * Nominatim.
 */
final class CityCentres
{
    /**
     * Cities whose name, or a word of it, starts with what was typed: "jai"
     * finds Jaipur, "delhi" finds Delhi and New Delhi. A state after the
     * name narrows it ("Udaipur, Tri", "udaipur tripura").
     *
     * @return list<array{label: string, area: null, city: string, state: string, latitude: float, longitude: float}>
     */
    public static function search(string $query, int $limit = 5): array
    {
        [$cityPart, $statePart] = array_pad(explode(',', $query, 2), 2, '');
        $cityPart = self::normalize($cityPart);
        $statePart = self::normalize($statePart);

        if (strlen($cityPart) < 2) {
            return [];
        }

        $matches = [];

        foreach (IndianCities::all() as $state => $cities) {
            if ($statePart !== '' && ! str_starts_with(self::normalize($state), $statePart)) {
                continue;
            }

            foreach ($cities as $city => [$latitude, $longitude, $population]) {
                if ($latitude === null) {
                    continue;
                }

                $city = (string) $city;
                $name = self::normalize($city);
                $rank = match (true) {
                    $name === $cityPart => 0,
                    // "jaipur raj": the city, then the start of its state.
                    $statePart === '' && str_starts_with($cityPart, $name.' ')
                        && str_starts_with(self::normalize($state), substr($cityPart, strlen($name) + 1)) => 0,
                    // Starts with it, or a word of it does ("delhi" → New Delhi).
                    str_starts_with($name, $cityPart), str_contains(' '.$name, ' '.$cityPart) => 1,
                    default => null,
                };

                if ($rank !== null) {
                    $matches[] = compact('rank', 'city', 'state', 'latitude', 'longitude', 'population');
                }
            }
        }

        // Exact names first, then the biggest towns: "jai" is Jaipur before
        // Jaisalmer, "aurangabad" is Maharashtra's before Bihar's.
        usort($matches, fn (array $a, array $b) => [$a['rank'], $b['population'], strlen($a['city'])] <=> [$b['rank'], $a['population'], strlen($b['city'])]);

        return array_map(fn (array $match) => [
            'label' => $match['city'].', '.$match['state'],
            'area' => null,
            'city' => $match['city'],
            'state' => $match['state'],
            'latitude' => $match['latitude'],
            'longitude' => $match['longitude'],
        ], array_slice($matches, 0, $limit));
    }

    /**
     * The centre of one city of the list, or null.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function find(?string $city, ?string $state): ?array
    {
        $city = self::normalize((string) $city);
        $state = self::normalize((string) $state);

        foreach (IndianCities::all() as $name => $cities) {
            if ($state !== '' && self::normalize($name) !== $state) {
                continue;
            }

            foreach ($cities as $cityName => [$latitude, $longitude]) {
                if ($latitude !== null && self::normalize((string) $cityName) === $city) {
                    return [$latitude, $longitude];
                }
            }
        }

        return null;
    }

    /**
     * Lower case, letters and digits only: what was typed in Hindi comes out
     * empty and goes to Nominatim, which reads it.
     */
    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
    }
}
