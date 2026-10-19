<?php

declare(strict_types=1);

return [

    // Stripe Connect. Without a secret key, payments answer 503 and the
    // rest of the platform works. Test-mode keys only, unless in production.
    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Platform fee on each order, in basis points (500 = 5%).
        'platform_fee_bps' => (int) env('PLATFORM_FEE_BPS', 500),
    ],

];
