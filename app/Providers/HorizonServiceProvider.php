<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Horizon is open in the local environment only. Store accounts never
     * get it: it shows every store's jobs.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (mixed $user = null): bool => false);
    }
}
