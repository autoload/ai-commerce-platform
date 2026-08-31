<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'price' => fake()->randomFloat(2, 1, 500),
        ];
    }

    /**
     * organization_id/store_id/product_id are excluded from ProductVariant's
     * fillable list (tenant FKs, and product_id isn't a plain attribute
     * either) — set via direct property assignment in afterMaking(), same
     * pattern as Product::factory()->forStore(). option_signature is
     * likewise excluded from $fillable and is hard-coded to '' here,
     * matching the only value this MVP (no option matrix) ever uses.
     */
    public function forProduct(Product $product): static
    {
        return $this->afterMaking(function (ProductVariant $variant) use ($product) {
            $variant->organization_id = $product->organization_id;
            $variant->store_id = $product->store_id;
            $variant->product_id = $product->id;
            $variant->option_signature = '';
        });
    }

    /**
     * Factory::create() returns the in-memory model as built, not a fresh
     * SELECT — refresh so DB-level defaults (status: draft) are reflected
     * on the returned instance rather than left unset.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ProductVariant $variant) {
            $variant->refresh();
        });
    }
}
