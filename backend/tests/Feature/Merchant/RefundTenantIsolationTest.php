<?php

namespace Tests\Feature\Merchant;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\CheckoutOrderCreationService;
use App\Services\InventoryAdjustmentService;
use App\Services\StripeRefundGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\Doubles\FakeRefundGateway;
use Tests\TestCase;

class RefundTenantIsolationTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private function fakeGateway(): FakeRefundGateway
    {
        $fake = new FakeRefundGateway;
        $this->app->instance(StripeRefundGateway::class, $fake);

        return $fake;
    }

    private function paidOrder(Organization $org, Store $store): Order
    {
        $customer = Customer::factory()->forStore($store)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'price' => 20.00,
            'status' => CatalogStatus::Active,
        ]);

        app(InventoryAdjustmentService::class)->adjust(
            $variant, 10, InventoryTransactionReason::Restock, null, null
        );

        $order = app(CheckoutOrderCreationService::class)->createPendingOrder(
            $customer,
            $store,
            [['variant' => $variant, 'quantity' => 1]],
            [
                'recipient_name' => 'Jane Doe',
                'line1' => '123 Main St',
                'city' => 'Springfield',
                'state' => 'IL',
                'postal_code' => '62701',
                'country' => 'US',
            ],
            'pi_test_'.Str::random(24),
        );

        $payment = $order->payments->first();
        $payment->status = PaymentStatus::Succeeded;
        $payment->save();

        $order->status = OrderStatus::Paid;
        $order->save();

        return $order->fresh();
    }

    public function test_a_refund_cannot_be_initiated_against_an_order_from_another_organization(): void
    {
        $this->fakeGateway();
        $orgA = $this->activeOrganization();
        $ownerA = $this->memberWithRole($orgA, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($orgA)->create();
        $orgB = $this->activeOrganization();
        $storeB = Store::factory()->forOrganization($orgB)->create();
        $orderInB = $this->paidOrder($orgB, $storeB);
        $token = $ownerA->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson(
            "/api/stores/{$storeA->id}/orders/{$orderInB->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        )->assertStatus(404);

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_a_refund_cannot_be_initiated_against_an_order_from_a_different_store_in_the_same_organization(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($org)->create();
        $storeB = Store::factory()->forOrganization($org)->create();
        $orderInB = $this->paidOrder($org, $storeB);
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson(
            "/api/stores/{$storeA->id}/orders/{$orderInB->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        )->assertStatus(404);
    }

    public function test_same_organization_store_admin_without_assignment_is_denied_not_leaked(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $unassignedStore = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($org, $unassignedStore);
        $token = $storeAdmin->createToken('t')->plainTextToken;

        // Same organization, so the store itself resolves (404 would be
        // wrong) — but no store_user row means 403, not access.
        $this->withToken($token)->postJson(
            "/api/stores/{$unassignedStore->id}/orders/{$order->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        )->assertStatus(403);
    }

    public function test_spoofed_store_id_and_organization_id_in_the_body_are_ignored(): void
    {
        $fake = $this->fakeGateway();
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $otherOrg = $this->activeOrganization();
        $otherStore = Store::factory()->forOrganization($otherOrg)->create();
        $order = $this->paidOrder($org, $store);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$order->id}/refund",
            [
                'idempotency_key' => 'refund-key-1',
                'store_id' => $otherStore->id,
                'organization_id' => $otherOrg->id,
                'order_id' => 999999,
            ]
        );

        $response->assertCreated();
        $this->assertDatabaseHas('refunds', [
            'order_id' => $order->id,
            'store_id' => $store->id,
            'organization_id' => $org->id,
        ]);
        $this->assertCount(1, $fake->calls);
    }

    public function test_merchant_token_cannot_reach_refund_route_via_platform_admin_identity(): void
    {
        $this->fakeGateway();
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($org, $store);

        $admin = PlatformAdmin::factory()->create();
        $token = $admin->createToken('platform')->plainTextToken;

        $this->withToken($token)->postJson(
            "/api/stores/{$store->id}/orders/{$order->id}/refund",
            ['idempotency_key' => 'refund-key-1']
        )->assertStatus(401);
    }
}
