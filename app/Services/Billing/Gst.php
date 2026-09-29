<?php

namespace App\Services\Billing;

use App\Models\EmployerProfile;
use App\Models\Setting;
use App\Support\GstStates;

/**
 * GST on what Super Karigar sells: the admin-set rate and seller details, and
 * how the tax on a sale splits.
 *
 * Everything here is editable in Admin → Settings → Billing & GST. The config
 * (config/billing.php, config/company.php) is only the fallback for a setting
 * nobody has saved yet.
 *
 * The split follows the place of supply:
 *  - buyer in the seller's state (the state in the seller's GSTIN) → half CGST,
 *    half SGST;
 *  - buyer in any other state → the whole rate as IGST.
 * The buyer's state comes from their GSTIN when they gave one, else from the
 * state on their profile. When neither is known the place of supply is the
 * seller's own state, which is what the GST rules say for a B2C sale with no
 * address on record.
 */
class Gst
{
    public const ENABLED_KEY = 'billing_gst_enabled';

    public const PERCENT_KEY = 'billing_gst_percent';

    public const SELLER_NAME_KEY = 'billing_seller_name';

    public const SELLER_ADDRESS_KEY = 'billing_seller_address';

    public const SELLER_GSTIN_KEY = 'billing_seller_gstin';

    public const SAC_KEY = 'billing_sac_code';

    /**
     * SAC for online advertising and job-listing services. The admin can
     * change it; check it with the company's CA.
     */
    public const DEFAULT_SAC = '998365';

    public static function enabled(): bool
    {
        return Setting::bool(self::ENABLED_KEY, true);
    }

    /**
     * The rate charged on new sales, 0 when GST is switched off.
     */
    public static function percent(): float
    {
        if (! self::enabled()) {
            return 0.0;
        }

        $value = Setting::get(self::PERCENT_KEY);

        return is_numeric($value) ? (float) $value : (float) config('billing.gst_percent', 18);
    }

    /**
     * @return array{name: string, address: string, gstin: string, email: string, state_code: ?string, sac: string}
     */
    public static function seller(): array
    {
        $gstin = strtoupper((string) (Setting::get(self::SELLER_GSTIN_KEY) ?: config('billing.seller.gstin')));

        return [
            'name' => (string) (Setting::get(self::SELLER_NAME_KEY) ?: config('billing.seller.name')),
            'address' => (string) (Setting::get(self::SELLER_ADDRESS_KEY) ?: config('billing.seller.address')),
            'gstin' => $gstin,
            'email' => (string) config('billing.seller.email'),
            'state_code' => GstStates::codeFromGstin($gstin),
            'sac' => (string) (Setting::get(self::SAC_KEY) ?: self::DEFAULT_SAC),
        ];
    }

    /**
     * A price with GST added, the amount the buyer actually pays.
     */
    public static function gross(float $price): float
    {
        return round($price + round($price * self::percent() / 100, 2), 2);
    }

    /**
     * The full breakup of GST on a taxable amount, for the given buyer.
     *
     * @return array{taxable: float, percent: float, gst: float, cgst: float, sgst: float, igst: float, total: float, intra_state: bool, place_of_supply: ?string, seller_gstin: string, sac: string}
     */
    public static function quote(float $taxable, ?EmployerProfile $buyer = null): array
    {
        $taxable = round($taxable, 2);
        $percent = self::percent();
        $gst = round($taxable * $percent / 100, 2);
        $seller = self::seller();

        $buyerState = GstStates::codeFromGstin($buyer?->gstin)
            ?? GstStates::codeFromName($buyer?->state)
            ?? $seller['state_code'];

        // Without a seller state there is nothing to compare against; IGST is
        // the safe reading for a sale whose origin we cannot place.
        $intra = $seller['state_code'] !== null && $buyerState === $seller['state_code'];

        $cgst = $intra ? round($gst / 2, 2) : 0.0;
        $sgst = $intra ? round($gst - $cgst, 2) : 0.0;

        return [
            'taxable' => $taxable,
            'percent' => $percent,
            'gst' => $gst,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $intra ? 0.0 : $gst,
            'total' => round($taxable + $gst, 2),
            'intra_state' => $intra,
            'place_of_supply' => $buyerState !== null ? GstStates::label($buyerState) : null,
            'seller_gstin' => $seller['gstin'],
            'sac' => $seller['sac'],
        ];
    }

    /**
     * The columns a subscription stores for its invoice.
     *
     * @param  array<string, mixed>  $quote  from {@see quote()}
     * @return array<string, mixed>
     */
    public static function subscriptionColumns(array $quote): array
    {
        return [
            'subtotal_amount' => $quote['taxable'],
            'gst_percent' => $quote['percent'],
            'gst_amount' => $quote['gst'],
            'cgst_amount' => $quote['cgst'],
            'sgst_amount' => $quote['sgst'],
            'igst_amount' => $quote['igst'],
            'total_amount' => $quote['total'],
            'place_of_supply' => $quote['place_of_supply'],
            'seller_gstin' => $quote['seller_gstin'],
            'sac_code' => $quote['sac'],
        ];
    }
}
