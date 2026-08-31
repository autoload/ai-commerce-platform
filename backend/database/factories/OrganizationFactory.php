<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
        ];
    }

    /**
     * Factory::create() returns the in-memory model as built, not a fresh
     * SELECT — refresh so DB-level defaults (status: pending) are reflected
     * on the returned instance rather than left unset.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization) {
            $organization->refresh();
        });
    }
}
