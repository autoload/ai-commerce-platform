<?php

namespace App\Http\Resources\Catalog;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, customer-facing product representation — deliberately distinct
 * from App\Http\Resources\ProductResource (the merchant/admin shape), which
 * exposes store_id/status/timestamps and has no images/options/variant
 * detail at all. Nested shapes are inlined as plain arrays rather than
 * split into separate Resource classes, matching OrderResource's existing
 * convention for items/shipping_address.
 *
 * Callers must eager-load category, images, options.values, and
 * variants.optionValues.option — this resource only reads what's already
 * loaded, it never triggers its own queries.
 *
 * @mixin Product
 */
class CatalogProductResource extends JsonResource
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
            'description' => $this->description,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null,
            'images' => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url,
                'is_primary' => $image->is_primary,
                'sort_order' => $image->sort_order,
            ])->all(),
            'options' => $this->options->map(fn ($option) => [
                'id' => $option->id,
                'name' => $option->name,
                'values' => $option->values->map(fn ($value) => [
                    'id' => $value->id,
                    'value' => $value->value,
                ])->all(),
            ])->all(),
            'variants' => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'compare_at_price' => $variant->compare_at_price,
                'options' => $variant->optionValues->map(fn ($optionValue) => [
                    'option' => $optionValue->option->name,
                    'value' => $optionValue->value,
                ])->all(),
            ])->all(),
        ];
    }
}
