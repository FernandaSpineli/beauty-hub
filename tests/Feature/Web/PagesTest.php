<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Pages;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Tenant::factory()->create(['slug' => 'bella', 'name' => 'Bella Cosméticos', 'theme_color' => '#2f7d6d']);
});

function page(string $host, string $path = '/'): TestResponse
{
    return test()->get("http://{$host}{$path}");
}

it('serves each store\'s storefront at any path, with its name and colour', function (string $path): void {
    page('bella.beautyhub.localhost', $path)
        ->assertOk()
        ->assertSee('<title>Bella Cosméticos</title>', false)
        ->assertSee('&quot;theme_color&quot;:&quot;#2f7d6d&quot;', false)
        ->assertHeader('Content-Security-Policy', Pages::CSP)
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
})->with(['/', '/produto/batom-matte', '/pedidos/0199f0c4-0000-7000-8000-000000000001', '/admin/estoque']);

it('answers unknown and suspended stores with a page, not JSON', function (): void {
    page('nope.beautyhub.localhost')->assertNotFound()->assertSee('Loja não encontrada');

    Tenant::query()->where('slug', 'bella')->update(['status' => 'suspended']);
    page('bella.beautyhub.localhost')->assertForbidden()->assertSee('Loja indisponível');
});

it('leaves API paths to the API', function (): void {
    $this->get('http://bella.beautyhub.localhost/api/v1/nope', ['Accept' => 'application/json'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('shows the platform page with its stores and the sign-up form', function (): void {
    page('beautyhub.localhost')
        ->assertOk()
        ->assertSee('Crie sua loja')
        ->assertSee('http://bella.beautyhub.localhost:8000/', false)
        ->assertHeader('Content-Security-Policy', Pages::CSP);
});

it('calls only API routes that exist', function (): void {
    $source = (string) file_get_contents(public_path('storefront/app.js'));
    preg_match_all('/api\("(GET|POST|PUT|PATCH|DELETE)", [`"]([^`"]+)[`"]/', $source, $calls, PREG_SET_ORDER);
    expect(count($calls))->toBeGreaterThan(25);

    foreach ($calls as [, $method, $path]) {
        $path = preg_replace('/\$\{[^}]+\}/', '0199f0c4-0000-7000-8000-000000000001', strtok($path, '?') ?: $path);
        $request = Request::create("http://bella.beautyhub.localhost/api/v1{$path}", $method);

        try {
            app('router')->getRoutes()->match($request);
        } catch (HttpException) {
            $this->fail("app.js calls {$method} {$path}, which has no route.");
        }
    }
});
