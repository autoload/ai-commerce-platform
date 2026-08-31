<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-fixture infrastructure only (Block 4C) — there is still no
 * merchant-facing or checkout order-creation path.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Order has no fillable attributes at all (see Order model docblock —
     * a financial record set only by explicit property assignment), so
     * definition() has nothing useful to return; every field is set
     * directly in forStore() below instead.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }

    /**
     * Builds a minimal, valid, `pending`-status Order (the DB column
     * default) against a freshly created Customer for the given Store.
     * Tests that need a different starting status set it directly after
     * creation (`$order->status = ...; $order->save();`), the same pattern
     * already used for Organization::status in CreatesTenantFixtures.
     */
    public function forStore(Store $store): static
    {
        return $this->afterMaking(function (Order $order) use ($store) {
            $customer = Customer::factory()->forStore($store)->create();

            $order->organization_id = $store->organization_id;
            $order->store_id = $store->id;
            $order->customer_id = $customer->id;
            $order->order_number = (string) Str::ulid();
            $order->subtotal = 50.00;
            $order->discount_total = 0;
            $order->tax_total = 0;
            $order->total = 50.00;
            $order->currency = 'usd';
            $order->customer_name = $customer->name;
            $order->customer_email = $customer->email;
        });
    }

    /**
     * Factory::create() returns the in-memory model as built, not a fresh
     * SELECT — refresh so the DB-level default (status: pending) is
     * reflected on the returned instance rather than left unset.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Order $order) {
            $order->refresh();
        });
    }
}
