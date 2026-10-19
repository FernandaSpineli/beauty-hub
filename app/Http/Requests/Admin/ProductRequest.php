<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ProductStatus;
use App\Enums\SkinType;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating requires the core fields; updating accepts any subset.
 */
final class ProductRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $required = $product instanceof Product ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:160'],
            'slug' => ['sometimes', 'string', 'max:160', 'regex:/\A[a-z0-9]+(-[a-z0-9]+)*\z/',
                Rule::unique('products', 'slug')->ignore($product instanceof Product ? $product->id : null)],
            // exists() runs under row-level security: another store's brand
            // or category is "not found".
            'brand_id' => [$required, 'uuid', Rule::exists('brands', 'id')],
            'category_id' => [$required, 'uuid', Rule::exists('categories', 'id')],
            'description' => ['sometimes', 'string', 'max:5000'],
            'ingredients' => ['sometimes', 'string', 'max:5000'],
            'skin_types' => ['sometimes', 'array', 'max:5'],
            'skin_types.*' => ['distinct', Rule::enum(SkinType::class)],
            'is_vegan' => ['sometimes', 'boolean'],
            'is_cruelty_free' => ['sometimes', 'boolean'],
            'anvisa_registration' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9.]{5,20}\z/'],
            'image_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
        ];
    }
}
