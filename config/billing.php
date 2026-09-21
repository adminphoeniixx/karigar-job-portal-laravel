<?php

// Laravel loads config files alphabetically, so `config('company.*')` is still
// empty while this file is being evaluated. Requiring it gives us the same
// array a beat early, and config caching bakes the result in either way.
$company = require __DIR__.'/company.php';

return [

    /*
    |--------------------------------------------------------------------------
    | GST
    |--------------------------------------------------------------------------
    | Percentage added on top of plan prices at checkout and printed on
    | tax invoices. Captured per-subscription, so changing it later only
    | affects new purchases.
    */

    'gst_percent' => (float) env('GST_PERCENT', 18),

    /*
    |--------------------------------------------------------------------------
    | Seller details printed on tax invoices
    |--------------------------------------------------------------------------
    | An invoice names the registered company, not the brand, so these fall
    | back to config/company.php rather than to app.name. The INVOICE_SELLER_*
    | keys stay as an override for the day billing moves to another entity.
    */

    'seller' => [
        'name' => env('INVOICE_SELLER_NAME') ?: $company['legal_name'],
        'address' => env('INVOICE_SELLER_ADDRESS') ?: $company['address'],
        'gstin' => env('INVOICE_SELLER_GSTIN') ?: $company['gstin'],
        'email' => env('INVOICE_SELLER_EMAIL') ?: $company['email'],
    ],

    'invoice_prefix' => env('INVOICE_PREFIX', 'KRG'),

    /*
    |--------------------------------------------------------------------------
    | Contact-credit top-ups
    |--------------------------------------------------------------------------
    | One-time credit packs sold outside a subscription ("Just need a few
    | unlocks?" on the Plans screen). Prices are inclusive of GST.
    */

    'credit_packs' => [
        'topup_25' => ['credits' => 25, 'price' => 299, 'label' => '25 credits'],
        'topup_60' => ['credits' => 60, 'price' => 649, 'label' => '60 credits'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job boost tiers
    |--------------------------------------------------------------------------
    | Credit cost and duration of each boost offered on the Manage Job screen.
    */

    'boost_tiers' => [
        'standard' => ['credits' => 1, 'days' => 3, 'label' => 'Standard boost'],
        'turbo' => ['credits' => 3, 'days' => 7, 'label' => 'Turbo boost'],
    ],
];
