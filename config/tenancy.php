<?php

declare(strict_types=1);

return [

    // Stores live at {slug}.{central_domain}; the platform itself (sign-up,
    // platform admin) at the central domain. *.localhost resolves to the
    // loopback address in browsers and curl, so no hosts file is needed.
    'central_domain' => env('CENTRAL_DOMAIN', 'beautyhub.localhost'),

];
