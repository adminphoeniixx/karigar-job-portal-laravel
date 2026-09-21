<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The legal entity behind Super Karigar
    |--------------------------------------------------------------------------
    | "Super Karigar" is the brand; this is the company that actually bills,
    | is registered for GST and receives grievances. It has to appear on the
    | site footer, on tax invoices and in outgoing mail — Razorpay and the GST
    | rules both want the registered name and GSTIN visible, not just the
    | brand — so it lives here once and is read everywhere else.
    |
    | Shared with the front end by HandleInertiaRequests as the `company` prop.
    */

    'legal_name' => env('COMPANY_LEGAL_NAME', 'Phoeniixx Designs Private Limited'),

    'address' => env('COMPANY_ADDRESS', '02-124, Blue One Square, Delhi-Jaipur Expressway, Phase IV'),

    'gstin' => env('COMPANY_GSTIN', '06AAFCP6967R1ZF'),

    'email' => env('COMPANY_EMAIL', 'care@phoeniixx.com'),

    'phone' => env('COMPANY_PHONE', '8860616130'),

];
