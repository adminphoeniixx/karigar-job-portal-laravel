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
];
