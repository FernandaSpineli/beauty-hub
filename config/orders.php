<?php

declare(strict_types=1);

return [

    // How long an order holds its stock while waiting for payment. Stripe
    // Checkout sessions need at least 30 minutes, so keep it above that.
    'reservation_minutes' => (int) env('ORDER_RESERVATION_MINUTES', 45),

];
