<?php

namespace App\Support;

use App\Models\JobListing;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Services\Geocoder;

/**
 * Where a karigar is, as an employer's card shows it: "1.5 km · Sanganer,
 * Jaipur". The distance is exact when the karigar's pin is their own (their
 * phone's position, or the area they picked) and approximate ("~4 km") when
 * it is only their city's centre. A karigar without any pin is measured from
 * their city's centre too.
 */
final class KarigarLocation
{
    /** Pin sources that say where the karigar really is. */
    private const EXACT = ['gps', 'area'];

    /**
     * @param  array{0: float, 1: float}|null  $origin  where the employer measures from
     * @return array{distance_km: float|null, distance_label: string|null, distance_approx: bool, area: string|null, location_label: string|null}
     */
    public static function for(?WorkerProfile $profile, ?array $origin): array
    {
        [$pin, $exact] = self::pin($profile);
        $km = $origin !== null && $pin !== null ? self::km($origin, $pin) : null;

        return [
            'distance_km' => $km,
            'distance_label' => $km === null ? null : self::format($km, $exact),
            'distance_approx' => $km !== null && ! $exact,
            'area' => $profile?->area,
            'location_label' => self::label($profile),
        ];
    }

    /**
     * "Sanganer, Jaipur", or just the city while the area is unknown.
     */
    public static function label(?WorkerProfile $profile): ?string
    {
        $parts = collect([$profile?->area, $profile?->city])
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->unique(fn (string $part) => mb_strtolower($part));

        return $parts->isEmpty() ? null : $parts->join(', ');
    }

    /**
     * Where an employer's distances are measured from: the point the app
     * sent, else the employer's own pin, else its city's centre.
     *
     * @param  array{0: float, 1: float}|null  $point
     * @return array{0: float, 1: float}|null
     */
    public static function employerOrigin(User $employer, ?array $point = null): ?array
    {
        if ($point !== null) {
            return $point;
        }

        $profile = $employer->employerAccount()->employerProfile;

        if ($profile?->latitude !== null && $profile?->longitude !== null) {
            return [(float) $profile->latitude, (float) $profile->longitude];
        }

        return app(Geocoder::class)->cityCentre($profile?->city, $profile?->state, lookup: false);
    }

    /**
     * An applicant is measured from the job's site, or the employer when the
     * job has no pin.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function jobOrigin(?JobListing $job, User $employer): ?array
    {
        if ($job?->latitude !== null && $job?->longitude !== null) {
            return [(float) $job->latitude, (float) $job->longitude];
        }

        return self::employerOrigin($employer);
    }

    /**
     * Straight-line km, one decimal.
     *
     * @param  array{0: float, 1: float}  $from
     * @param  array{0: float, 1: float}  $to
     */
    public static function km(array $from, array $to): float
    {
        [$lat1, $lng1] = $from;
        [$lat2, $lng2] = $to;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    /**
     * "800 m", "1.5 km", "3 km", "24 km"; "~4 km" when approximate, and
     * nothing for an approximate distance under a kilometre (same city).
     */
    public static function format(float $km, bool $exact = true): ?string
    {
        if (! $exact) {
            return $km < 1 ? null : '~'.number_format(round($km)).' km';
        }

        return match (true) {
            $km < 1 => max(100, (int) round($km * 10) * 100).' m',
            $km < 10 => rtrim(rtrim(number_format($km, 1), '0'), '.').' km',
            default => number_format(round($km)).' km',
        };
    }

    /**
     * The karigar's pin and whether it is their own.
     *
     * @return array{0: array{0: float, 1: float}|null, 1: bool}
     */
    private static function pin(?WorkerProfile $profile): array
    {
        if ($profile === null) {
            return [null, false];
        }

        if ($profile->latitude !== null && $profile->longitude !== null) {
            return [
                [(float) $profile->latitude, (float) $profile->longitude],
                in_array($profile->location_source, self::EXACT, true),
            ];
        }

        return [app(Geocoder::class)->cityCentre($profile->city, $profile->state, lookup: false), false];
    }
}
