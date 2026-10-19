<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Turns a variant into a kit made of other variants (or back, with an empty
 * list). Kits do not nest, and a variant holding stock cannot become one.
 */
final class KitController
{
    public function update(Request $request, ProductVariant $variant): JsonResponse
    {
        /** @var array{components: list<array{variant_id: string, quantity: int}>} $data */
        $data = $request->validate([
            'components' => ['present', 'array', 'max:10'],
            'components.*.variant_id' => ['required', 'uuid', 'distinct', Rule::exists('product_variants', 'id'),
                Rule::notIn([$variant->id])],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);
        $components = [];
        foreach ($data['components'] as $component) {
            $components[$component['variant_id']] = ['quantity' => (int) $component['quantity']];
        }

        DB::transaction(function () use ($variant, $components): void {
            if ($components !== []) {
                $this->assertCanBecomeKit($variant, array_keys($components));
            }

            $variant->components()->sync($components);
        });

        $variant->load('components', 'stock');

        return new JsonResponse(['data' => [
            'variant_id' => $variant->id,
            'available' => $variant->stock->available ?? 0,
            'components' => $variant->components->map(fn (ProductVariant $c): array => [
                'variant_id' => $c->id,
                'sku' => $c->sku,
                'name' => $c->name,
                'quantity' => $components[$c->id]['quantity'] ?? 0,
            ])->values(),
        ]]);
    }

    /**
     * @param  list<string>  $componentIds
     */
    private function assertCanBecomeKit(ProductVariant $variant, array $componentIds): void
    {
        if ($variant->lots()->exists()) {
            throw new ApiException(422, 'VARIANT_HAS_STOCK', 'A variant with stock lots cannot become a kit.');
        }

        $isComponent = DB::table('kit_components')->where('component_variant_id', $variant->id)->exists();
        $hasKitComponent = DB::table('kit_components')->whereIn('kit_variant_id', $componentIds)->exists();

        if ($isComponent || $hasKitComponent) {
            throw new ApiException(422, 'KIT_NESTING', 'Kits cannot contain kits or be part of another kit.');
        }
    }
}
