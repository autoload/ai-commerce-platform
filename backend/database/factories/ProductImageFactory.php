<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-fixture infrastructure only (Phase 1 — catalog isolation tests).
 *
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'url' => fake()->unique()->imageUrl(),
            'is_primary' => false,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->afterMaking(function (ProductImage $image) use ($product) {
            $image->product_id = $product->id;
        });
    }
}
