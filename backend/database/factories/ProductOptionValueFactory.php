<?php

namespace Database\Factories;

use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-fixture infrastructure only (Phase 1 — catalog isolation tests).
 *
 * @extends Factory<ProductOptionValue>
 */
class ProductOptionValueFactory extends Factory
{
    protected $model = ProductOptionValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'value' => fake()->unique()->word(),
        ];
    }

    public function forOption(ProductOption $option): static
    {
        return $this->afterMaking(function (ProductOptionValue $value) use ($option) {
            $value->product_option_id = $option->id;
        });
    }
}
