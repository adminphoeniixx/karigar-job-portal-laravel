<?php

namespace App\Services\Billing;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tax invoice numbers: one series per Indian financial year (April to March),
 * consecutive with no gaps, as GST rule 46 asks. KRG/26-27/00001 is the first
 * invoice of FY 2026-27; 15 characters, under the rule's limit of 16.
 *
 * Call it inside the transaction that saves the invoice. The counter row stays
 * locked until that transaction ends, and a rollback hands the number back, so
 * a failed save never leaves a hole in the series.
 */
class InvoiceNumber
{
    /**
     * @return array{number: string, financial_year: string, sequence: int}
     */
    public static function next(CarbonInterface $issuedAt): array
    {
        $year = self::financialYear($issuedAt);

        DB::table('invoice_counters')->insertOrIgnore([
            'financial_year' => $year,
            'last_sequence' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = 1 + (int) DB::table('invoice_counters')
            ->where('financial_year', $year)
            ->lockForUpdate()
            ->value('last_sequence');

        DB::table('invoice_counters')
            ->where('financial_year', $year)
            ->update(['last_sequence' => $sequence, 'updated_at' => now()]);

        return [
            'number' => sprintf('%s/%s/%05d', config('billing.invoice_prefix', 'KRG'), substr($year, 2), $sequence),
            'financial_year' => $year,
            'sequence' => $sequence,
        ];
    }

    /**
     * "2026-27" for any day from 1 April 2026 to 31 March 2027, in India time.
     */
    public static function financialYear(CarbonInterface $at): string
    {
        $local = $at->copy()->timezone(config('app.display_timezone'));
        $start = $local->month >= 4 ? $local->year : $local->year - 1;

        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }
}
