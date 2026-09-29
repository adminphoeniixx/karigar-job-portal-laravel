<?php

namespace App\Support;

/**
 * Indian states and union territories by their GST state code — the first two
 * digits of every GSTIN. The code is how an invoice's place of supply is
 * compared with the seller's registration.
 */
final class GstStates
{
    public const CODES = [
        '01' => 'Jammu and Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '27' => 'Maharashtra',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
    ];

    /** Spellings people type that are not the official name. */
    private const ALIASES = [
        'new delhi' => '07',
        'nct of delhi' => '07',
        'orissa' => '21',
        'pondicherry' => '34',
        'j&k' => '01',
        'jammu & kashmir' => '01',
        'andaman & nicobar islands' => '35',
        'daman and diu' => '26',
        'dadra and nagar haveli' => '26',
    ];

    /**
     * The state code of a GSTIN, or null when it does not look like one.
     */
    public static function codeFromGstin(?string $gstin): ?string
    {
        $gstin = strtoupper(trim((string) $gstin));

        if (! preg_match('/^\d{2}[A-Z0-9]{13}$/', $gstin)) {
            return null;
        }

        $code = substr($gstin, 0, 2);

        return isset(self::CODES[$code]) ? $code : null;
    }

    /**
     * The state code for a state name as typed on a profile, or null.
     */
    public static function codeFromName(?string $name): ?string
    {
        $key = strtolower(trim((string) $name));

        if ($key === '') {
            return null;
        }

        foreach (self::CODES as $code => $state) {
            if (strtolower($state) === $key) {
                return $code;
            }
        }

        return self::ALIASES[$key] ?? null;
    }

    public static function name(string $code): ?string
    {
        return self::CODES[$code] ?? null;
    }

    /** "Haryana (06)", the way an invoice prints the place of supply. */
    public static function label(string $code): string
    {
        return (self::CODES[$code] ?? 'Unknown').' ('.$code.')';
    }
}
