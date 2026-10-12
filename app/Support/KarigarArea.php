<?php

namespace App\Support;

use App\Models\WorkerProfile;
use App\Services\Geocoder;

/**
 * Keeps a karigar's area and map pin in step when their profile is saved,
 * from the app or the website, so employers see "1.5 km · Sanganer" only
 * when it is true:
 *
 *  - a new position from the phone or a pin they placed is their own; the
 *    area is read off the map unless they typed one;
 *  - an area they typed or picked puts the pin on that area;
 *  - a new city alone moves the pin to the city's centre (an approximate
 *    distance) and drops the old city's area.
 *
 * Clients may say where a new position came from in `location_source`:
 * `gps` (the phone, or a pin placed by hand), `area` (a place picked from
 * the search) or `city` (only centred on the city, as the website's map does
 * when the city changes). Call after the profile is filled, before saving.
 */
final class KarigarArea
{
    /**
     * @param  array<string, mixed>  $input  the validated request
     */
    public static function apply(WorkerProfile $profile, array $input, Geocoder $geocoder): void
    {
        $hint = $input['location_source'] ?? null;
        $pointChanged = self::pointChanged($profile);

        // The pin was taken away: nothing to keep. An area or a city below
        // may place it again.
        if ($pointChanged && ($profile->latitude === null || $profile->longitude === null)) {
            $profile->location_source = null;
            $pointChanged = false;
        }
        $areaChanged = $profile->isDirty('area');
        $cityChanged = mb_strtolower(trim((string) $profile->city)) !== mb_strtolower(trim((string) $profile->getOriginal('city')));

        if ($pointChanged && $hint !== 'city') {
            if (filled($profile->area) && ($areaChanged || $hint === 'area')) {
                // A place picked from the search, with its area.
                $profile->location_source = 'area';

                return;
            }

            // Their own position: name the area from the map.
            $place = $geocoder->reverse((float) $profile->latitude, (float) $profile->longitude);
            $profile->area = $place['area'] ?? null;
            $profile->city = filled($profile->city) ? $profile->city : ($place['city'] ?? null);
            $profile->state = filled($profile->state) ? $profile->state : ($place['state'] ?? null);
            $profile->location_source = 'gps';

            return;
        }

        if (filled($profile->area) && ($areaChanged || $cityChanged)) {
            $pin = $geocoder->locate($profile->area, $profile->city, $profile->state);

            if ($pin !== null) {
                [$profile->latitude, $profile->longitude] = $pin;
                $profile->location_source = 'area';

                return;
            }
        }

        if ($cityChanged || ($pointChanged && $hint === 'city')) {
            if (! $pointChanged) {
                $centre = $geocoder->cityCentre($profile->city, $profile->state, lookup: false)
                    ?? CityCentres::find($profile->city, $profile->state);
                $profile->latitude = $centre[0] ?? null;
                $profile->longitude = $centre[1] ?? null;
            }

            // The area was in the old city.
            if ($cityChanged && ! $areaChanged) {
                $profile->area = null;
            }

            $profile->location_source = $profile->latitude !== null ? 'city' : null;
        }
    }

    /**
     * Whether the pin moved. Compared as numbers: the database hands back
     * "26.9124000" for the "26.9124" a form sends.
     */
    private static function pointChanged(WorkerProfile $profile): bool
    {
        foreach (['latitude', 'longitude'] as $field) {
            $now = $profile->getAttribute($field);
            $before = $profile->getOriginal($field);

            if (($now === null) !== ($before === null)) {
                return true;
            }

            if ($now !== null && abs((float) $now - (float) $before) > 0.000001) {
                return true;
            }
        }

        return false;
    }
}
