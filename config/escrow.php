<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Platform commission
    |--------------------------------------------------------------------------
    | Percentage the platform keeps when an escrow is released to a worker.
    | With bearer = "worker", the fee is deducted from the worker's payout
    | (worker receives amount - fee). With "employer", it would be added on
    | top of the funded amount instead.
    */
    'commission_percent' => (float) env('ESCROW_COMMISSION_PERCENT', 10),

    'fee_bearer' => env('ESCROW_FEE_BEARER', 'worker'), // worker | employer

    'currency' => env('ESCROW_CURRENCY', 'INR'),
];
