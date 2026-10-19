<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Resources\StoreResource;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The HTML shells. Everything else is the JavaScript app in
 * public/storefront, talking to the same JSON API as any client.
 */
final class Pages
{
    /** Scripts, styles and API calls from this origin only; images may be remote (product photos). */
    public const string CSP = "default-src 'self'; img-src 'self' https: data:; script-src 'self'; style-src 'self'; "
        ."connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'";

    public function storefront(Request $request, TenantContext $context): Response
    {
        $store = $context->require();

        return $this->page('storefront', [
            'store' => (new StoreResource($store))->resolve($request),
            'title' => $store->name,
        ]);
    }

    public function platform(): Response
    {
        return $this->page('platform', [
            'stores' => Tenant::query()->where('status', 'active')->orderBy('created_at')->limit(12)->get(['slug', 'name', 'theme_color']),
            'title' => 'Beauty Hub',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(string $view, array $data): Response
    {
        return response()->view($view, $data)->header('Content-Security-Policy', self::CSP);
    }
}
