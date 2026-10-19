<?php

declare(strict_types=1);

return [

    // Per store, email and IP: slows down password guessing.
    'login_per_minute' => (int) env('RATE_LIMIT_LOGIN_PER_MINUTE', 5),

    // Per store and IP: sign-ups and password reset emails.
    'auth_per_minute' => (int) env('RATE_LIMIT_AUTH_PER_MINUTE', 10),

    // Per user, or per IP for anonymous requests.
    'api_per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 300),

];
