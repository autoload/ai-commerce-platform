<?php

namespace Database\Factories;

use App\Enums\OrderAddressType;
use App\Models\Order;
use App\Models\OrderAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-fixture infrastructure only (Block 4C).
 *
 * @extends Factory<OrderAddress>
 */
class OrderAddressFactory extends Factory
{
    protected $model = OrderAddress::class;

    /**
     * OrderAddress has no fillable attributes — every field is set
     * directly in forOrder() below.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }

    public function forOrder(Order $order): static
    {
        return $this->afterMaking(function (OrderAddress $address) use ($order) {
            $address->order_id = $order->id;
            $address->type = OrderAddressType::Shipping;
            $address->recipient_name = fake()->name();
            $address->line1 = fake()->streetAddress();
            $address->line2 = null;
            $address->city = fake()->city();
            $address->state = fake()->state();
            $address->postal_code = fake()->postcode();
            $address->country = 'US';
            $address->phone = null;
        });
    }
}
