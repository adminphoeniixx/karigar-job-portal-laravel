<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Voice agent provider
    |--------------------------------------------------------------------------
    | Which App\Services\Screening\VoiceAgent places the automated screening
    | call. "stub" records everything and dials nobody — it is the default so
    | that deploying this feature before the telephony account exists cannot
    | start calling real workers.
    */
    'provider' => env('SCREENING_PROVIDER', 'stub'), // livekit | stub

    /*
    |--------------------------------------------------------------------------
    | Caller identity
    |--------------------------------------------------------------------------
    | One virtual number for the whole platform, not one per employer. The
    | worker sees this number; the employer's name is spoken in the greeting
    | instead, because a phone call cannot carry a name.
    |
    | Register this number with Truecaller Business so it shows as the brand
    | rather than an unknown number — that is what actually lifts pick-up rates.
    */
    'from_number' => env('SCREENING_FROM_NUMBER'),

    'brand' => env('SCREENING_BRAND', 'Super Karigar'),

    // Provider voice id. One voice everywhere, so workers start recognising it.
    'voice' => env('SCREENING_VOICE', 'anushka'),

    'default_language' => env('SCREENING_LANGUAGE', 'hi'),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    | Workers are on site with the phone in a bag; the first call is missed far
    | more often than it is answered. Retries only ever follow a no-answer or a
    | busy tone — never a call the worker actually took.
    */
    'max_attempts' => (int) env('SCREENING_MAX_ATTEMPTS', 3),

    'retry_after_minutes' => (int) env('SCREENING_RETRY_AFTER', 90),

    /*
    |--------------------------------------------------------------------------
    | Calling hours (IST)
    |--------------------------------------------------------------------------
    | TRAI restricts automated voice calls to daytime hours. A call that comes
    | due outside this window is held until the window reopens rather than
    | dropped. Keep this inside 09:00–21:00.
    */
    'window' => [
        'start' => env('SCREENING_WINDOW_START', '10:00'),
        'end' => env('SCREENING_WINDOW_END', '19:00'),
        'timezone' => env('SCREENING_TIMEZONE', 'Asia/Kolkata'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Interview slots the agent may offer
    |--------------------------------------------------------------------------
    | How far ahead the worker may propose a slot. The agent collects a
    | preference; the employer confirms it before anyone is committed.
    */
    'slot_window_days' => (int) env('SCREENING_SLOT_WINDOW_DAYS', 5),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    | Shared secret the provider signs its result callback with. Without it the
    | webhook is refused outright — anyone who can POST to it could otherwise
    | book interviews and forge transcripts.
    */
    'webhook_secret' => env('SCREENING_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | LiveKit
    |--------------------------------------------------------------------------
    | LiveKit supplies the agent runtime, speech services and the SIP bridge.
    | It does NOT supply Indian phone numbers — LiveKit's own numbers are US
    | only — so `sip_trunk_id` points at a trunk we configure against an Indian
    | carrier (Exotel / Plivo / Ozonetel). That carrier holds the +91 number and
    | the DLT registration; LiveKit just dials through it.
    |
    | Two calls place a screening call: dispatch the agent into a room, then
    | dial the worker into that same room. The script travels as dispatch
    | metadata, so the agent knows what to say before the worker answers.
    */
    'livekit' => [
        // wss://your-project.livekit.cloud — the REST API lives on the https
        // form of the same host, which the agent derives itself.
        'url' => env('LIVEKIT_URL'),
        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),

        // Outbound trunk created in LiveKit against the Indian carrier's SIP
        // credentials. Without it there is nothing to dial through.
        'sip_trunk_id' => env('LIVEKIT_SIP_TRUNK_ID'),

        // Name the agent worker registers under. Must match the agent service.
        'agent_name' => env('LIVEKIT_AGENT_NAME', 'screening-agent'),

        'timeout' => (int) env('LIVEKIT_TIMEOUT', 20),
    ],
];
