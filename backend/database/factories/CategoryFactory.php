<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-fixture infrastructure only (Phase 1 — catalog isolation tests need
 * a real Category row) — no category-management feature exists yet.
 *
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
        ];
    }

    /**
     * organization_id/store_id are excluded from Category's fillable list
     * (tenant FKs are never mass-assignable) — set via direct property
     * assignment, same pattern as Product::factory()->forStore().
     */
    public function forStore(Store $store): static
    {
        return $this->afterMaking(function (Category $category) use ($store) {
            $category->organization_id = $store->organization_id;
            $category->store_id = $store->id;
        });
    }
}
