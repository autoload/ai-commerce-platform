<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-fixture infrastructure only (Block 4C).
 *
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * OrderItem has no fillable attributes — every field is set directly
     * in forOrder() below. product_id/product_variant_id are deliberately
     * left null: OrderItem's snapshot fields (product_name/sku/unit_price)
     * are what Order detail actually displays, and are independent of
     * whether a live catalog row still exists.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }

    public function forOrder(Order $order): static
    {
        return $this->afterMaking(function (OrderItem $item) use ($order) {
            $item->order_id = $order->id;
            $item->product_name = ucfirst(fake()->words(3, true));
            $item->sku = strtoupper(fake()->bothify('SKU-####??'));
            $item->unit_price = 25.00;
            $item->quantity = 2;
            $item->line_total = 50.00;
        });
    }
}
