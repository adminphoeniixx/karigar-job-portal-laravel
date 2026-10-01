<?php

namespace App\Support;

/**
 * Wages are monthly everywhere: what a job pays, what a karigar expects, and
 * what the apps show. A figure that arrives per day or per hour (an older app
 * build, or data from before the switch) is turned into its monthly amount.
 */
final class Wage
{
    /** Working days in a month, the usual count for a day's wage. */
    public const DAYS_PER_MONTH = 26;

    /** Working hours in a day, for an hourly wage. */
    public const HOURS_PER_DAY = 8;

    public const MONTHLY = 'monthly';

    /**
     * How many of `$period` make a month; 1 for monthly or unknown.
     */
    public static function factor(?string $period): int
    {
        return match ($period) {
            'hourly' => self::DAYS_PER_MONTH * self::HOURS_PER_DAY,
            'daily' => self::DAYS_PER_MONTH,
            default => 1,
        };
    }

    /**
     * The monthly amount of a wage given per `$period`, rounded to the rupee.
     * Null stays null; a non-numeric value is left for validation to reject.
     */
    public static function monthly(mixed $amount, ?string $period): mixed
    {
        if ($amount === null || $amount === '' || ! is_numeric($amount)) {
            return $amount;
        }

        return round((float) $amount * self::factor($period));
    }
}
