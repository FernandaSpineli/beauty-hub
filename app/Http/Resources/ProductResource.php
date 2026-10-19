<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\SkinType;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'description' => $this->description,
            'ingredients' => $this->ingredients,
            'skin_types' => $this->skin_types->map(fn (SkinType $type): string => $type->value)->all(),
            'is_vegan' => $this->is_vegan,
            'is_cruelty_free' => $this->is_cruelty_free,
            'anvisa_registration' => $this->anvisa_registration,
            'image_url' => $this->image_url,
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
