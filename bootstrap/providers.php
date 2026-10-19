<?php

declare(strict_types=1);

use App\Providers\ApiDocsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    ApiDocsServiceProvider::class,
    HorizonServiceProvider::class,
];
