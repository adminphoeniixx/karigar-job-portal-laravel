<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Voice call provider
    |--------------------------------------------------------------------------
    | Which App\Services\Calling\CallProvider drives in-app voice calls.
    | "agora" issues real RTC tokens; "stub" hands out dummy credentials so the
    | rest of the flow (ringing push, call log, guards) can be exercised
    | locally and in tests without an Agora account.
    |
    | Do not rename "stub" to "null" — env() turns the string "null" into an
    | actual null and this would silently fall back to Agora.
    */
    'provider' => env('CALL_PROVIDER', 'agora'), // agora | stub

    'agora' => [
        // Both are 32-character hex strings from the Agora console. The
        // certificate is a secret — app id alone is safe to ship to clients.
        'app_id' => env('AGORA_APP_ID'),
        'app_certificate' => env('AGORA_APP_CERTIFICATE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token lifetime
    |--------------------------------------------------------------------------
    | How long a join token stays valid. This caps a single call's length: the
    | SDK is disconnected when the token expires, so keep it comfortably longer
    | than a realistic call. Tokens are per-call and per-user, never reused.
    */
    'token_ttl' => (int) env('CALL_TOKEN_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Ring timeout
    |--------------------------------------------------------------------------
    | Seconds a call rings before it is marked missed. The Flutter side should
    | use the same number so its CallKit UI gives up at the same moment.
    */
    'ring_timeout' => (int) env('CALL_RING_TIMEOUT', 45),

    /*
    |--------------------------------------------------------------------------
    | Abuse limits
    |--------------------------------------------------------------------------
    | Calling costs no credits — it is the cheap alternative to unlocking a
    | number — so these limits are the only thing standing between a worker and
    | an employer who dials them twenty times an hour.
    */
    'limits' => [
        // Calls one caller may start per day, across all counterparts.
        'per_caller_daily' => (int) env('CALL_LIMIT_CALLER_DAILY', 50),
        // Calls one caller may start to the *same* person per day.
        'per_pair_daily' => (int) env('CALL_LIMIT_PAIR_DAILY', 5),
    ],
];
