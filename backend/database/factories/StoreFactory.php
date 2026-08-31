<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Store';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
        ];
    }

    /**
     * organization_id is deliberately excluded from Store's fillable list
     * (tenant FKs are never mass-assignable, per the Phase 3 fillable
     * convention), so it can't be set via definition()/create($attrs) — set
     * it via direct property assignment instead, the same pattern already
     * used for organization_user rows in MerchantAuthController.
     */
    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(function (Store $store) use ($organization) {
            $store->organization_id = $organization->id;
        });
    }

    /**
     * Factory::create() returns the in-memory model as built, not a fresh
     * SELECT — refresh so DB-level defaults (status: active) are reflected
     * on the returned instance rather than left unset.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Store $store) {
            $store->refresh();
        });
    }
}
