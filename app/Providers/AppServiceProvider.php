<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\PostgresConnection;
use App\Enums\UserRole;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\StripeGateway;
use App\Payments\UnavailableGateway;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use PDO;
use RuntimeException;
use Stripe\StripeClient;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh, empty context for every request and queued job.
        $this->app->scoped(TenantContext::class);

        $this->app->singleton(PaymentGateway::class, function (): PaymentGateway {
            $secret = config('services.stripe.secret');
            if (! is_string($secret) || $secret === '') {
                return new UnavailableGateway;
            }
            if (str_starts_with($secret, 'sk_live_') && ! $this->app->isProduction()) {
                throw new RuntimeException('Live Stripe keys are only accepted when APP_ENV=production.');
            }

            return new StripeGateway(new StripeClient($secret));
        });

        Connection::resolverFor('pgsql', fn (Closure|PDO $pdo, string $database, string $prefix, array $config): PostgresConnection => new PostgresConnection($pdo, $database, $prefix, $config));
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::define('manage-store', fn (User $user): bool => $user->role->managesStore());
        Gate::define('manage-payments', fn (User $user): bool => $user->role === UserRole::Owner);

        // A reconnect opens a new PostgreSQL session without app.tenant_id;
        // copy the current tenant into it before any query runs.
        Event::listen(function (ConnectionEstablished $event): void {
            $context = $this->app->make(TenantContext::class);

            if ($event->connectionName === TenantContext::CONNECTION && $context->current() !== null) {
                $context->applyTo($event->connection);
            }
        });

        $this->configureRateLimits();
        $this->runJobsAsTheirStore();
    }

    /**
     * A job dispatched while serving a store runs as that store: the tenant
     * ID travels in Laravel's Context (restored before this listener runs)
     * and is set before each job. Workers reuse their database connection,
     * so the tenant is also set or cleared explicitly every time; nothing
     * carries over from the previous job.
     */
    private function runJobsAsTheirStore(): void
    {
        Event::listen(function (JobProcessing $event): void {
            $context = $this->app->make(TenantContext::class);
            $id = Context::get('tenant_id');
            $store = is_string($id) ? Tenant::query()->find($id) : null;

            $store === null ? $context->clear() : $context->set($store);
        });

        Event::listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], function (): void {
            $this->app->make(TenantContext::class)->clear();
        });
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
