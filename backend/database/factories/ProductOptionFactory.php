<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-fixture infrastructure only (Phase 1 — catalog isolation tests need
 * a real ProductOption row) — no option-matrix feature exists yet
 * (Block 4A deliberately deferred it; every product ships with exactly one
 * default variant and no options in the current merchant-facing flow).
 *
 * @extends Factory<ProductOption>
 */
class ProductOptionFactory extends Factory
{
    protected $model = ProductOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Color', 'Size', 'Material', 'Style']),
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->afterMaking(function (ProductOption $option) use ($product) {
            $option->product_id = $product->id;
        });
    }
}
