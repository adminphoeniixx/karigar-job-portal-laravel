<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The company and contact details the site prints, edited in Admin →
 * Settings → Website. Kept as one JSON setting over config/company.php and
 * config/support.php, which stay the defaults until the admin saves.
 *
 * - Company: the registered entity behind the brand. Footers, the privacy
 *   page, mail footers and (unless Billing names another seller) tax
 *   invoices print it.
 * - Support: the apps' Help & Support channels, and the grievance address
 *   and courts named in the privacy policy and terms.
 *
 * A field the admin saved empty stays empty (a phone or WhatsApp number can
 * be taken off); one never saved falls back to the config default.
 */
final class SiteInfo
{
    public const KEY = 'site_info';

    /** The fields on the shared `company` prop. */
    public const COMPANY = ['legal_name', 'address', 'gstin', 'email', 'phone'];

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'legal_name' => (string) config('company.legal_name'),
            'address' => (string) config('company.address'),
            'gstin' => (string) config('company.gstin'),
            'email' => (string) config('company.email'),
            'phone' => (string) config('company.phone'),
            'support_email' => (string) config('support.email'),
            'support_phone' => (string) config('support.phone'),
            'support_whatsapp' => (string) config('support.whatsapp'),
            'support_hours' => (string) config('support.hours'),
            'grievance_email' => (string) config('support.grievance_email'),
            'jurisdiction' => (string) config('support.jurisdiction'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $stored = json_decode((string) Setting::get(self::KEY), true);
        $stored = is_array($stored) ? $stored : [];

        $info = [];

        foreach (self::defaults() as $key => $default) {
            $info[$key] = is_string($stored[$key] ?? null) ? $stored[$key] : $default;
        }

        return $info;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    /**
     * @return array<string, string>
     */
    public static function company(): array
    {
        return array_intersect_key(self::all(), array_flip(self::COMPANY));
    }

    /**
     * @param  array<string, mixed>  $info
     */
    public static function save(array $info): void
    {
        $keep = array_map(
            fn ($value) => trim((string) $value),
            array_intersect_key($info, self::defaults()),
        );

        Setting::set(self::KEY, json_encode($keep, JSON_UNESCAPED_UNICODE));
    }
}
