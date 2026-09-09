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

            $this->applyOrderFields($order, $customer);
        });
    }

    /**
     * Phase 9C addition: builds an Order against an already-existing
     * Customer, instead of forStore()'s "always create a fresh Customer"
     * behavior — needed for aggregate tests (order_count/total_spent) that
     * require several orders belonging to the SAME customer. organization_
     * id/store_id are derived from the customer (never passed separately),
     * matching how Customer itself is store-scoped. As with forStore(),
     * a non-default `total`/`status` is set directly after creation
     * (`$order->total = ...; $order->save();`), not via this state.
     */
    public function forCustomer(Customer $customer): static
    {
        return $this->afterMaking(function (Order $order) use ($customer) {
            $this->applyOrderFields($order, $customer);
        });
    }

    private function applyOrderFields(Order $order, Customer $customer): void
    {
        $order->organization_id = $customer->organization_id;
        $order->store_id = $customer->store_id;
        $order->customer_id = $customer->id;
        $order->order_number = (string) Str::ulid();
        $order->subtotal = 50.00;
        $order->discount_total = 0;
        $order->tax_total = 0;
        $order->total = 50.00;
        $order->currency = 'usd';
        $order->customer_name = $customer->name;
        $order->customer_email = $customer->email;
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
