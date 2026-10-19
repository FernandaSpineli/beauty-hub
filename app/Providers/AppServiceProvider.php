<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh, empty context for every request and queued job.
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // A reconnect opens a new PostgreSQL session without app.tenant_id;
        // copy the current tenant into it before any query runs.
        Event::listen(function (ConnectionEstablished $event): void {
            $context = $this->app->make(TenantContext::class);

            if ($event->connectionName === TenantContext::CONNECTION && $context->current() !== null) {
                $context->applyTo($event->connection);
            }
        });

        $this->configureRateLimits();
    }

    private function configureRateLimits(): void
    {
        $store = fn (): string => $this->app->make(TenantContext::class)->id() ?? 'platform';

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(config()->integer('rate_limits.login_per_minute'))
            ->by($store().'|'.mb_strtolower($request->string('email')->toString()).'|'.$request->ip()));

        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(config()->integer('rate_limits.auth_per_minute'))
            ->by($store().'|'.$request->ip()));

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(config()->integer('rate_limits.api_per_minute'))
            ->by($request->user()?->getAuthIdentifier() ?? $store().'|'.$request->ip()));
    }
}
