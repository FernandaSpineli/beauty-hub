<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
 * docs/openapi.json is generated from the code (make docs). These tests
 * fail when the committed document no longer matches the routes.
 */

function freshSpec(): array
{
    $path = storage_path('framework/testing/openapi.json');
    @mkdir(dirname($path), 0777, true);
    Artisan::call('scramble:export', ['--path' => $path]);

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('is up to date with the code (run "make docs")', function (): void {
    $committed = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(freshSpec())->toBe($committed);
});

it('documents every API route', function (): void {
    $documented = collect(freshSpec()['paths'])
        ->flatMap(fn (array $operations, string $path): array => array_map(fn (string $m): string => strtoupper($m).' /api'.$path, array_keys($operations)));

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/'))
        ->flatMap(fn ($route): array => array_map(fn (string $m): string => "{$m} /".preg_replace('/\{(\w+)\??\}/', '{$1}', $route->uri()), array_diff($route->methods(), ['HEAD'])));

    expect($routes->diff($documented)->values()->all())->toBe([]);
});

it('marks exactly the routes that need a token', function (): void {
    $spec = freshSpec();

    expect($spec['paths']['/v1/products']['get']['security'])->toBe([])
        ->and($spec['paths']['/v1/auth/login']['post']['security'])->toBe([])
        ->and($spec['paths']['/v1/orders']['post'])->not->toHaveKey('security')
        ->and($spec['security'])->toBe([['http' => []]])
        ->and($spec['components']['responses']['ValidationException']['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/Error']);
});
