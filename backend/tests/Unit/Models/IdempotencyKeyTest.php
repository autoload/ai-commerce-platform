<?php

namespace Tests\Unit\Models;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Database Design 2.6 — verifies the durable, DB-backed idempotency-key
 * uniqueness scopes directly against the schema, per "Order & Payment
 * State Models" §11: orders.idempotency_key is scoped per customer,
 * payments.idempotency_key is scoped per order — neither is globally
 * unique. No Checkout/CheckoutService exists yet; these tests write
 * directly to the columns, the same discipline Order/Payment's own empty
 * $fillable already forces on every other write path in this codebase.
 */
class IdempotencyKeyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['status' => 'active']);
        $this->store = Store::factory()->forOrganization($org)->create();
    }

    private function orderFor(Customer $customer): Order
    {
        $order = Order::factory()->forStore($this->store)->create();
        $order->customer_id = $customer->id;
        $order->save();

        return $order;
    }

    // --- L/M: orders.idempotency_key scoped per customer ---

    public function test_the_same_idempotency_key_is_allowed_for_different_customers(): void
    {
        $customerA = Customer::factory()->forStore($this->store)->create();
        $customerB = Customer::factory()->forStore($this->store)->create();
        $orderA = $this->orderFor($customerA);
        $orderB = $this->orderFor($customerB);

        $orderA->idempotency_key = 'shared-key';
        $orderA->save();

        $orderB->idempotency_key = 'shared-key';
        $orderB->save();

        $this->assertDatabaseHas('orders', ['id' => $orderA->id, 'idempotency_key' => 'shared-key']);
        $this->assertDatabaseHas('orders', ['id' => $orderB->id, 'idempotency_key' => 'shared-key']);
    }

    public function test_the_same_customer_cannot_reuse_an_idempotency_key_across_two_orders(): void
    {
        $customer = Customer::factory()->forStore($this->store)->create();
        $orderA = $this->orderFor($customer);
        $orderB = $this->orderFor($customer);

        $orderA->idempotency_key = 'duplicate-key';
        $orderA->save();

        $this->expectException(QueryException::class);

        $orderB->idempotency_key = 'duplicate-key';
        $orderB->save();
    }

    // --- N/O: payments.idempotency_key scoped per order ---

    public function test_different_payment_attempts_for_the_same_order_can_use_different_keys(): void
    {
        $customer = Customer::factory()->forStore($this->store)->create();
        $order = $this->orderFor($customer);

        // idempotency_key is set via direct property assignment, not
        // Factory::create(['idempotency_key' => ...]) — Payment's $fillable
        // is empty (same discipline as Order), so mass-assignment would
        // silently discard (or, under strict mode, throw for) an attribute
        // that isn't fillable.
        $p1 = Payment::factory()->forOrder($order)->create();
        $p1->idempotency_key = 'attempt-1';
        $p1->save();

        $p2 = Payment::factory()->forOrder($order)->create();
        $p2->idempotency_key = 'attempt-2';
        $p2->save();

        $this->assertDatabaseHas('payments', ['id' => $p1->id, 'idempotency_key' => 'attempt-1']);
        $this->assertDatabaseHas('payments', ['id' => $p2->id, 'idempotency_key' => 'attempt-2']);
    }

    public function test_the_same_order_and_key_cannot_be_inserted_twice(): void
    {
        $customer = Customer::factory()->forStore($this->store)->create();
        $order = $this->orderFor($customer);
        $p1 = Payment::factory()->forOrder($order)->create();
        $p1->idempotency_key = 'duplicate-attempt';
        $p1->save();

        $p2 = Payment::factory()->forOrder($order)->create();

        $this->expectException(QueryException::class);

        $p2->idempotency_key = 'duplicate-attempt';
        $p2->save();
    }
}
