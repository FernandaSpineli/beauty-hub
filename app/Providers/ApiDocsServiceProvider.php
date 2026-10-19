<?php

declare(strict_types=1);

namespace App\Providers;

use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\ServerVariable;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

/**
 * OpenAPI document generated from the code by Scramble (docs/openapi.json,
 * and /docs/api locally), adjusted for what Scramble cannot infer:
 * per-store servers, which routes need a token, and this API's error
 * envelope instead of Laravel's default error shapes.
 *
 * Scramble's fluent methods are untyped, so values are built step by step.
 */
final class ApiDocsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var GeneratorConfig $config */
        $config = Scramble::configure();
        $config->routes(fn (Route $route): bool => str_starts_with($route->uri, 'api/'));
        $config->withDocumentTransformers(fn (OpenApi $openApi) => $this->document($openApi));
        $config->withOperationTransformers(fn (Operation $operation, RouteInfo $route) => $this->operation($operation, $route));
    }

    private function document(OpenApi $openApi): void
    {
        $openApi->info->description = (string) file_get_contents(base_path('docs/api-intro.md'));

        $store = new Server("{$this->scheme()}://{store}.{$this->host()}/api");
        $store->description = 'A store, by its subdomain';
        $store->variables = ['store' => new ServerVariable('bella', description: 'Store subdomain')];
        $openApi->servers = [$store];

        /** @var SecurityScheme $bearer */
        $bearer = SecurityScheme::http('bearer');
        $bearer->description = 'Token from /v1/auth/login or /v1/auth/register; valid only at the store that issued it.';
        $openApi->secure($bearer);

        $openApi->components->schemas['Error'] = $this->errorSchema();
        foreach ($openApi->components->responses as $response) {
            if ($response instanceof Response) {
                $response->setContent('application/json', new Reference('schemas', 'Error', $openApi->components));
            }
        }
    }

    private function operation(Operation $operation, RouteInfo $route): void
    {
        // Public unless the route actually requires a token.
        if (! in_array('auth:sanctum', $route->route->gatherMiddleware(), true)) {
            $operation->security = [];
        }

        // Store routes use the document's {store} server; store sign-up
        // lives on the platform domain.
        $operation->servers = [];
        if ($route->route->getDomain() === config()->string('tenancy.central_domain')) {
            $platform = new Server("{$this->scheme()}://{$this->host()}/api");
            $platform->description = 'The platform';
            $operation->servers = [$platform];
        }

        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && (int) $response->code >= 400) {
                $response->setContent('application/json', $this->errorSchema());
            }
        }
    }

    private function errorSchema(): Schema
    {
        $code = new StringType;
        $code->setDescription('Stable and machine-readable, e.g. INSUFFICIENT_STOCK');
        $details = new ArrayType;
        $details->setDescription('Per-field problems ({field, message}) or other context');
        $requestId = new StringType;
        $requestId->setDescription('Also in the X-Request-Id header and the logs');

        $error = new ObjectType;
        $error->addProperty('code', $code);
        $error->addProperty('message', new StringType);
        $error->addProperty('details', $details);
        $error->addProperty('request_id', $requestId);
        $error->setRequired(['code', 'message', 'details', 'request_id']);

        $envelope = new ObjectType;
        $envelope->addProperty('error', $error);
        $envelope->setRequired(['error']);

        /** @var Schema */
        return Schema::fromType($envelope);
    }

    private function scheme(): string
    {
        $scheme = parse_url(config()->string('app.url'), PHP_URL_SCHEME);

        return is_string($scheme) ? $scheme : 'https';
    }

    private function host(): string
    {
        $port = parse_url(config()->string('app.url'), PHP_URL_PORT);

        return config()->string('tenancy.central_domain').(is_int($port) ? ":{$port}" : '');
    }
}
