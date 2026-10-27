<?php

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
    | An invoice names the registered company, not the brand. Unset, these
    | fall back to the company on Admin → Settings → Website (Gst::seller()).
    | The INVOICE_SELLER_* keys stay as an override for the day billing moves
    | to another entity.
    */

    'seller' => [
        'name' => env('INVOICE_SELLER_NAME'),
        'address' => env('INVOICE_SELLER_ADDRESS'),
        'gstin' => env('INVOICE_SELLER_GSTIN'),
        'email' => env('INVOICE_SELLER_EMAIL'),
    ],

    'invoice_prefix' => env('INVOICE_PREFIX', 'KRG'),
];
