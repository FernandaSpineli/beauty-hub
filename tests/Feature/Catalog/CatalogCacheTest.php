<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->bella = Tenant::factory()->create(['slug' => 'bella']);
    $this->glow = Tenant::factory()->create(['slug' => 'glow']);
    $this->category = asStore($this->bella, fn () => Category::factory()->create(['name' => 'Maquiagem']));
    asStore($this->glow, fn () => Category::factory()->create(['name' => 'Perfumaria']));
});

function categoryNames(string $store): array
{
    return array_column(test()->getJson(test()->storeUrl($store, 'categories'))->assertOk()->json('data'), 'name');
}

it('serves the category tree from cache', function (): void {
    expect(categoryNames('bella'))->toBe(['Maquiagem']);

    // A write that skips model events is not seen until the cache expires.
    asStore($this->bella, fn () => DB::table('categories')->update(['name' => 'Make']));

    expect(categoryNames('bella'))->toBe(['Maquiagem']);
});

it('clears the store\'s cache when its catalog changes', function (): void {
    expect(categoryNames('bella'))->toBe(['Maquiagem']);

    $this->withToken(tokenFor($this->bella))
        ->patchJson($this->storeUrl('bella', "admin/categories/{$this->category->id}"), ['name' => 'Make'])
        ->assertOk();

    expect(categoryNames('bella'))->toBe(['Make']);
});

it('never serves one store\'s cached catalog to another', function (): void {
    expect(categoryNames('bella'))->toBe(['Maquiagem'])
        ->and(categoryNames('glow'))->toBe(['Perfumaria'])
        ->and(categoryNames('bella'))->toBe(['Maquiagem']);
});
