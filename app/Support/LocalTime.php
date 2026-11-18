<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The clock people read: the app stores UTC, the dashboards and reports count
 * by the Indian day. A signup at 01:00 IST belongs to that morning, not to the
 * evening before, which is where a plain DATE(created_at) puts it.
 */
class LocalTime
{
    public static function zone(): string
    {
        return config('app.display_timezone', 'Asia/Kolkata');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone());
    }

    /**
     * SQL for a UTC timestamp column read on the display-timezone clock.
     * Postgres in production, SQLite under phpunit.
     */
    public static function sql(string $column): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return sprintf("datetime(%s, '%+d minutes')", $column, intdiv(self::now()->getOffset(), 60));
        }

        return sprintf("((%s at time zone 'UTC') at time zone '%s')", $column, self::zone());
    }

    /**
     * SQL that names the local day, week (its Monday) or month a UTC timestamp
     * falls in: 2026-11-17, 2026-11-16, 2026-11.
     */
    public static function bucket(string $column, string $unit): string
    {
        $local = self::sql($column);

        if (DB::connection()->getDriverName() === 'sqlite') {
            return match ($unit) {
                'month' => "strftime('%Y-%m', $local)",
                'week' => "date($local, 'weekday 0', '-6 days')",
                default => "strftime('%Y-%m-%d', $local)",
            };
        }

        return match ($unit) {
            'month' => "to_char($local, 'YYYY-MM')",
            'week' => "to_char(date_trunc('week', $local), 'YYYY-MM-DD')",
            default => "to_char($local, 'YYYY-MM-DD')",
        };
    }
}
