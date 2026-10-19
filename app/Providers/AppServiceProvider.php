<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\PostgresConnection;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use PDO;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh, empty context for every request and queued job.
        $this->app->scoped(TenantContext::class);

        Connection::resolverFor('pgsql', fn (Closure|PDO $pdo, string $database, string $prefix, array $config): PostgresConnection => new PostgresConnection($pdo, $database, $prefix, $config));
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::define('manage-store', fn (User $user): bool => $user->role->managesStore());

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
