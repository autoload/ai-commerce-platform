<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
        ];
    }

    /**
     * organization_id/store_id are excluded from Product's fillable list
     * (tenant FKs are never mass-assignable, per the Phase 3 convention) —
     * set via direct property assignment, same pattern as
     * StoreFactory::forOrganization(). organization_id is derived from the
     * store, never supplied independently, matching how a product is
     * actually created (there is no such thing as a product in an
     * organization without a specific store).
     */
    public function forStore(Store $store): static
    {
        return $this->afterMaking(function (Product $product) use ($store) {
            $product->organization_id = $store->organization_id;
            $product->store_id = $store->id;
        });
    }

    /**
     * Factory::create() returns the in-memory model as built, not a fresh
     * SELECT — refresh so DB-level defaults (status: draft) are reflected
     * on the returned instance rather than left unset.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Product $product) {
            $product->refresh();
        });
    }
}
