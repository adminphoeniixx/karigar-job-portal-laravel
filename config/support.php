<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Help & Support contact channels
    |--------------------------------------------------------------------------
    | Shown on the apps' "Help & Support" screen. A channel left empty is
    | omitted from the API response, so the app never renders a dead row —
    | switch one on by filling its env var, not by editing the app.
    */

    'email' => env('SUPPORT_EMAIL', 'support@superkarigar.com'),

    // Digits with country code, no "+" — the app builds the wa.me link.
    'whatsapp' => env('SUPPORT_WHATSAPP', ''),

    'phone' => env('SUPPORT_PHONE', ''),

    'hours' => env('SUPPORT_HOURS', 'Monday to Saturday, 10 AM to 7 PM IST'),

    /*
    |--------------------------------------------------------------------------
    | Legal
    |--------------------------------------------------------------------------
    | Printed inside the privacy policy and terms of use.
    */

    'grievance_email' => env('GRIEVANCE_EMAIL', 'privacy@superkarigar.com'),

    'jurisdiction' => env('LEGAL_JURISDICTION', 'Jaipur, Rajasthan'),

];
