<?php

namespace App\Services\Kyc;

/**
 * Do two names on two documents belong to the same person or business?
 *
 * Registry names are rarely spelled the same twice: "RAJESH KUMAR" on a PAN is
 * "Rajesh Kumar Sharma" on Aadhaar, "R K SHARMA" elsewhere, a company drops its
 * "Pvt Ltd". So: titles and company suffixes go, and every word of the shorter
 * name has to appear in the longer one, as the whole word, its initial, or
 * one letter off (Mohammad / Mohammed). At least one whole word has to match,
 * so two sets of initials never pass.
 */
final class NameMatcher
{
    /** Words that say nothing about who it is. */
    private const NOISE = [
        'MR', 'MRS', 'MS', 'MISS', 'SHRI', 'SRI', 'SMT', 'KUMARI', 'DR', 'MD',
        'PVT', 'PRIVATE', 'LTD', 'LIMITED', 'LLP', 'CO', 'COMPANY', 'THE', 'AND', 'OPC',
        'INDIA',
    ];

    public static function matches(?string $a, ?string $b): bool
    {
        $left = self::tokens($a);
        $right = self::tokens($b);

        if ($left === [] || $right === []) {
            return false;
        }

        // "RAMKUMAR" against "RAM KUMAR" (but initials alone never match).
        if (implode('', $left) === implode('', $right) && max(array_map('strlen', $left)) >= 2) {
            return true;
        }

        [$short, $long] = count($left) <= count($right) ? [$left, $right] : [$right, $left];
        $wholeWordMatched = false;

        foreach ($short as $word) {
            $found = false;

            foreach ($long as $other) {
                if (self::sameWord($word, $other)) {
                    $found = true;
                    $wholeWordMatched = true;
                    break;
                }

                if (self::isInitialOf($word, $other) || self::isInitialOf($other, $word)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return $wholeWordMatched;
    }

    /**
     * @return list<string>
     */
    public static function tokens(?string $name): array
    {
        $name = strtoupper((string) $name);
        // "M/S Sharma Traders": the M/S is a salutation, not two initials.
        $name = (string) preg_replace('#\bM\s*/\s*S\.?#', ' ', $name);
        $name = (string) preg_replace('/[^A-Z]+/', ' ', $name);

        return array_values(array_filter(
            explode(' ', $name),
            fn (string $word) => $word !== '' && ! in_array($word, self::NOISE, true),
        ));
    }

    private static function sameWord(string $a, string $b): bool
    {
        if (strlen($a) < 2 || strlen($b) < 2) {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        return min(strlen($a), strlen($b)) >= 5 && levenshtein($a, $b) <= 1;
    }

    private static function isInitialOf(string $initial, string $word): bool
    {
        return strlen($initial) === 1 && str_starts_with($word, $initial);
    }
}
