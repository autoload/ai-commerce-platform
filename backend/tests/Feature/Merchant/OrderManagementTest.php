<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private function paidOrder(Store $store): Order
    {
        $order = Order::factory()->forStore($store)->create();
        $order->status = OrderStatus::Paid;
        $order->save();

        return $order;
    }

    public function test_owner_can_list_orders_for_a_store(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        Order::factory()->forStore($store)->create();
        Order::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/orders");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_response_does_not_include_items_or_shipping_address(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        OrderItem::factory()->forOrder($order)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/orders");

        $response->assertOk();
        $this->assertArrayNotHasKey('items', $response->json('data.0'));
        $this->assertArrayNotHasKey('shipping_address', $response->json('data.0'));
    }

    public function test_owner_can_view_order_detail_with_items_and_shipping_address(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        // OrderItem/OrderAddress have empty $fillable — create()'s attribute
        // array can't mass-assign, same as Order itself — set the fields we
        // want to assert on directly, after creation.
        $item = OrderItem::factory()->forOrder($order)->create();
        $item->product_name = 'Blue Mug';
        $item->sku = 'MUG-BLUE';
        $item->save();
        $address = OrderAddress::factory()->forOrder($order)->create();
        $address->recipient_name = 'Jane Doe';
        $address->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.items.0.product_name', 'Blue Mug')
            ->assertJsonPath('data.items.0.sku', 'MUG-BLUE')
            ->assertJsonPath('data.shipping_address.recipient_name', 'Jane Doe');
    }

    public function test_store_admin_can_list_and_view_assigned_store_orders(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $order = Order::factory()->forStore($store)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/orders")->assertOk();
        $this->withToken($token)->getJson("/api/stores/{$store->id}/orders/{$order->id}")->assertOk();
    }

    public function test_store_admin_cannot_list_or_view_unassigned_store_orders(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/orders")->assertStatus(403);
        $this->withToken($token)->getJson("/api/stores/{$store->id}/orders/{$order->id}")->assertStatus(403);
    }

    public function test_staff_can_view_but_not_update_status(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $order = $this->paidOrder($store);
        $token = $staff->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/orders/{$order->id}")->assertOk();

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'processing',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_owner_cannot_update_status_while_organization_is_pending_but_can_still_view(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/orders/{$order->id}")->assertOk();

        $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'processing',
        ])->assertStatus(403);
    }

    public function test_status_filter_returns_only_matching_orders(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $this->paidOrder($store);
        Order::factory()->forStore($store)->create(); // pending
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/orders?status=paid");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('paid', $data[0]['status']);
    }

    public function test_invalid_status_filter_value_is_rejected(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/orders?status=not-a-status");

        $response->assertStatus(422);
    }

    public function test_orders_are_sorted_by_created_at_descending(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $older = Order::factory()->forStore($store)->create();
        $older->created_at = now()->subDay();
        $older->save();
        $newer = Order::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/orders");

        $response->assertOk();
        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
    }

    public function test_pagination_defaults_to_fifteen_per_page(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        Order::factory()->forStore($store)->count(16)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/orders");

        $response->assertOk();
        $this->assertCount(15, $response->json('data'));
        $this->assertSame(16, $response->json('meta.total'));
    }

    public function test_valid_transition_pending_to_cancelled(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'cancelled',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertNotNull($order->fresh()->cancelled_at);
    }

    public function test_valid_transition_paid_to_processing(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'processing',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'processing');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'processing']);
    }

    public function test_valid_transition_processing_to_shipped(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $order->status = OrderStatus::Processing;
        $order->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'shipped',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'shipped');
    }

    public function test_valid_transition_shipped_to_completed(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $order->status = OrderStatus::Shipped;
        $order->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'completed',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_paid_to_cancelled_is_explicitly_rejected(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'cancelled',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_pending_to_paid_is_rejected_by_field_validation(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'paid',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }

    public function test_any_status_to_refunded_is_rejected_by_field_validation(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'refunded',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_skip_ahead_transitions_are_rejected(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $paidOrder = $this->paidOrder($store);
        $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$paidOrder->id}/status", [
            'status' => 'shipped',
        ])->assertStatus(422);

        $processingOrder = $this->paidOrder($store);
        $processingOrder->status = OrderStatus::Processing;
        $processingOrder->save();
        $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$processingOrder->id}/status", [
            'status' => 'completed',
        ])->assertStatus(422);
    }

    public function test_backward_transition_is_rejected(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $order->status = OrderStatus::Shipped;
        $order->save();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'processing',
        ]);

        $response->assertStatus(422);
    }

    public function test_terminal_state_mutations_are_rejected(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        foreach (['completed', 'cancelled'] as $terminal) {
            $order = Order::factory()->forStore($store)->create();
            $order->status = OrderStatus::from($terminal);
            $order->save();

            $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
                'status' => 'processing',
            ]);

            $response->assertStatus(422);
        }
    }

    public function test_immutable_fields_cannot_be_modified_through_patch_status(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $otherStore = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $originalTotal = $order->total;
        $originalCustomerId = $order->customer_id;
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'processing',
            'total' => 999999,
            'subtotal' => 999999,
            'customer_id' => 999999,
            'store_id' => $otherStore->id,
            'organization_id' => 999999,
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertEquals($originalTotal, $order->total);
        $this->assertSame($originalCustomerId, $order->customer_id);
        $this->assertSame($store->id, $order->store_id);
    }

    public function test_status_reason_is_not_written_by_the_merchant_endpoint(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'cancelled',
            'status_reason' => 'customer_cancelled',
        ])->assertOk();

        $this->assertNull($order->fresh()->status_reason);
    }

    public function test_update_status_uses_select_for_update_locking(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $token = $owner->createToken('t')->plainTextToken;

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'processing',
        ])->assertOk();

        $lockingQueryFound = collect($queries)->contains(
            fn ($sql) => str_contains(strtolower($sql), 'orders') && str_contains(strtolower($sql), 'for update')
        );

        $this->assertTrue($lockingQueryFound, 'Expected a SELECT ... FOR UPDATE query against orders during the status update.');
    }

    /**
     * Forces a genuine failure *inside* the locked transaction, after the
     * transition is validated but during the save, to prove
     * DB::transaction() rolls back — not just that validation can reject a
     * request beforehand.
     */
    public function test_forced_failure_during_status_update_rolls_back(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $token = $owner->createToken('t')->plainTextToken;

        Order::saving(function () {
            throw new RuntimeException('Simulated failure to verify order status rollback.');
        });

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", [
            'status' => 'processing',
        ]);

        $response->assertStatus(500);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_nonexistent_order_returns_404(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/orders/999999")->assertStatus(404);
        $this->withToken($token)->patchJson("/api/stores/{$store->id}/orders/999999/status", [
            'status' => 'processing',
        ])->assertStatus(404);
    }

    public function test_unauthenticated_requests_are_rejected_on_every_order_route(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $order = Order::factory()->forStore($store)->create();

        $this->getJson("/api/stores/{$store->id}/orders")->assertStatus(401);
        $this->getJson("/api/stores/{$store->id}/orders/{$order->id}")->assertStatus(401);
        $this->patchJson("/api/stores/{$store->id}/orders/{$order->id}/status", ['status' => 'cancelled'])->assertStatus(401);
    }
}
