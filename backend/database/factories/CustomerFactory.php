<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Test-fixture infrastructure only (Block 4C) — Order fixtures require a
 * real Customer row (orders.customer_id is NOT NULL, restrictOnDelete).
 * No customer-management feature exists; this factory is not one.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'password' => static::$password ??= Hash::make('password'),
        ];
    }

    /**
     * organization_id/store_id are excluded from Customer's fillable list
     * (tenant FKs are never mass-assignable) — set via direct property
     * assignment, same pattern as Product::factory()->forStore().
     */
    public function forStore(Store $store): static
    {
        return $this->afterMaking(function (Customer $customer) use ($store) {
            $customer->organization_id = $store->organization_id;
            $customer->store_id = $store->id;
        });
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Customer $customer) {
            $customer->refresh();
        });
    }
}
