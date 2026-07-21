<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'msg91' => [
        'authkey' => env('MSG91_AUTHKEY'),
        'template_id' => env('MSG91_TEMPLATE_ID'),
    ],

    'razorpay' => [
        'key' => env('RAZORPAY_KEY'),
        'secret' => env('RAZORPAY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    'razorpayx' => [
        // RazorpayX virtual account number used as the payout source.
        'account_number' => env('RAZORPAYX_ACCOUNT_NUMBER'),
    ],

    'bunnycdn' => [
        'storage_zone' => env('BUNNYCDN_STORAGE_ZONE'),
        'api_key' => env('BUNNYCDN_API_KEY'),
        'region' => env('BUNNYCDN_REGION', ''),
        'host' => env('BUNNYCDN_HOST', 'storage.bunnycdn.com'),
        'pull_zone_url' => env('BUNNYCDN_PULL_ZONE_URL'),
        'token_auth_key' => env('BUNNYCDN_TOKEN_AUTH_KEY'),
        // How long a signed (token-authenticated) URL stays valid. Default 7 days.
        'url_ttl' => (int) env('BUNNYCDN_URL_TTL', 604800),
    ],

];
