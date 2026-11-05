<?php

namespace App\Support;

/**
 * Every city and town of India by state, with its centre and population:
 * resources/data/indian-cities.php (~8,900 places, every district included).
 *
 * The list is generated, not hand-written: the old curated major cities
 * (centres from Nominatim), every district and every city/town on Wikidata,
 * and the GeoNames towns with a census population, merged and de-duplicated
 * per state. The web dropdowns read the same names from
 * resources/js/data/indianCities.json, generated alongside it.
 */
final class IndianCities
{
    /** @var array<string, array<string, array{0: float|null, 1: float|null, 2: int}>>|null */
    private static ?array $data = null;

    /**
     * @return array<string, array<string, array{0: float|null, 1: float|null, 2: int}>> state => city => [latitude, longitude, population]
     */
    public static function all(): array
    {
        return self::$data ??= require resource_path('data/indian-cities.php');
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return array_keys(self::all());
    }

    /**
     * The cities of one state, A–Z. The state matches regardless of case.
     *
     * @return list<string>
     */
    public static function citiesFor(?string $state): array
    {
        $cities = self::citiesOf($state);

        return $cities === null ? [] : array_map('strval', array_keys($cities));
    }

    /**
     * @return array<string, array{0: float|null, 1: float|null, 2: int}>|null
     */
    private static function citiesOf(?string $state): ?array
    {
        $state = trim((string) $state);

        if ($state === '') {
            return null;
        }

        $all = self::all();

        if (isset($all[$state])) {
            return $all[$state];
        }

        foreach ($all as $name => $cities) {
            if (strcasecmp($name, $state) === 0) {
                return $cities;
            }
        }

        return null;
    }
}
