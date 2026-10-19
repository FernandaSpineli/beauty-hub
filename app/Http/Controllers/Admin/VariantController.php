<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Resources\VariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ForeignKeyGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Shades and sizes of a product.
 */
final class VariantController
{
    public function store(Request $request, Product $product): VariantResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules());
        $variant = $product->variants()->make($data);
        $this->checkCompareAtPrice($variant, $data);
        $variant->save();

        return new VariantResource($variant);
    }

    public function update(Request $request, ProductVariant $variant): VariantResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules($variant));
        $variant->fill($data);
        $this->checkCompareAtPrice($variant, $data);
        $variant->save();

        return new VariantResource($variant);
    }

    public function destroy(ProductVariant $variant): Response
    {
        ForeignKeyGuard::delete(
            fn () => $variant->delete(),
            'VARIANT_IN_USE',
            'This variant has stock or orders; deactivate it instead.',
        );

        return response()->noContent();
    }

    /**
     * The "was" price must stay above the price. Sent explicitly, a wrong
     * one is an error; left over from a sale the new price ends, it is
     * dropped.
     *
     * @param  array<string, mixed>  $data
     */
    private function checkCompareAtPrice(ProductVariant $variant, array $data): void
    {
        $compareAt = $variant->compare_at_price_cents;
        if ($compareAt === null || $compareAt > $variant->price_cents) {
            return;
        }

        if (array_key_exists('compare_at_price_cents', $data)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Some fields are invalid.', [
                ['field' => 'compare_at_price_cents', 'message' => 'The compare-at price must be greater than the price.'],
            ]);
        }

        $variant->compare_at_price_cents = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?ProductVariant $variant = null): array
    {
        $required = $variant ? 'sometimes' : 'required';

        return [
            'sku' => [$required, 'string', 'max:40', 'regex:/\A[A-Z0-9]+(-[A-Z0-9]+)*\z/',
                Rule::unique('product_variants', 'sku')->ignore($variant?->id)],
            'name' => [$required, 'string', 'max:80'],
            'shade_hex' => ['sometimes', 'nullable', 'regex:/\A#[0-9a-f]{6}\z/'],
            'price_cents' => [$required, 'integer', 'min:1', 'max:100000000'],
            'compare_at_price_cents' => ['sometimes', 'nullable', 'integer', 'max:100000000'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
