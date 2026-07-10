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
    */

    'seller' => [
        'name' => env('INVOICE_SELLER_NAME', config('app.name', 'Karigar')),
        'address' => env('INVOICE_SELLER_ADDRESS', ''),
        'gstin' => env('INVOICE_SELLER_GSTIN', ''),
        'email' => env('INVOICE_SELLER_EMAIL', ''),
    ],

    'invoice_prefix' => env('INVOICE_PREFIX', 'KRG'),
];
