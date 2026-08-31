<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Models\InventoryTransaction;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    public function test_owner_can_view_inventory_for_a_never_adjusted_variant(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory");

        $response->assertOk()
            ->assertJsonPath('data.product_variant_id', $variant->id)
            ->assertJsonPath('data.quantity_on_hand', 0);

        // A read must not materialize a row — nothing has been adjusted yet.
        $this->assertDatabaseMissing('inventory', ['product_variant_id' => $variant->id]);
    }

    public function test_owner_can_restock_inventory(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 25,
            'reason' => 'restock',
            'note' => 'Initial stock',
        ]);

        $response->assertOk()->assertJsonPath('data.quantity_on_hand', 25);

        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 25]);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_variant_id' => $variant->id,
            'delta' => 25,
            'reason' => 'restock',
            'note' => 'Initial stock',
        ]);
    }

    public function test_store_admin_can_adjust_inventory_on_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 10,
            'reason' => 'restock',
        ]);

        $response->assertOk()->assertJsonPath('data.quantity_on_hand', 10);
    }

    public function test_store_admin_cannot_adjust_inventory_on_unassigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 10,
            'reason' => 'restock',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('inventory', ['product_variant_id' => $variant->id]);
    }

    public function test_staff_can_view_but_not_adjust_inventory(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $staff->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory")->assertOk();

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 10,
            'reason' => 'restock',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('inventory', ['product_variant_id' => $variant->id]);
    }

    public function test_owner_cannot_adjust_inventory_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 10,
            'reason' => 'restock',
        ]);

        $response->assertStatus(403);
    }

    public function test_negative_resulting_inventory_is_rejected(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 5,
            'reason' => 'restock',
        ])->assertOk();

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => -10,
            'reason' => 'adjustment',
        ]);

        $response->assertStatus(422);
        // Unchanged — the rejected adjustment must not have partially applied.
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 5]);
        $this->assertSame(1, InventoryTransaction::where('product_variant_id', $variant->id)->count());
    }

    public function test_adjustment_requires_delta_and_reason(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['delta', 'reason']);
    }

    public function test_zero_delta_is_rejected(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 0,
            'reason' => 'restock',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['delta']);
    }

    public function test_sale_and_refund_reasons_are_rejected_from_the_merchant_endpoint(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 5,
            'reason' => 'sale',
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 5,
            'reason' => 'refund',
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_multiple_adjustments_accumulate_correctly(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", ['delta' => 20, 'reason' => 'restock'])->assertOk();
        $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", ['delta' => -5, 'reason' => 'adjustment'])->assertOk();
        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", ['delta' => 10, 'reason' => 'restock']);

        $response->assertOk()->assertJsonPath('data.quantity_on_hand', 25);
        $this->assertSame(3, InventoryTransaction::where('product_variant_id', $variant->id)->count());
    }

    public function test_adjustment_uses_select_for_update_locking(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 5,
            'reason' => 'restock',
        ])->assertOk();

        $lockingQueryFound = collect($queries)->contains(
            fn ($sql) => str_contains(strtolower($sql), 'inventory') && str_contains(strtolower($sql), 'for update')
        );

        $this->assertTrue($lockingQueryFound, 'Expected a SELECT ... FOR UPDATE query against inventory during adjustment.');
    }

    /**
     * Forces a genuine failure *inside* the locked transaction, after the
     * quantity_on_hand update but during the ledger insert, to prove
     * DB::transaction() rolls back both — not just that validation can
     * reject a request beforehand.
     */
    public function test_forced_failure_during_adjustment_rolls_back_both_quantity_and_ledger(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        // Establish a known baseline first, outside the forced-failure hook.
        $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 10,
            'reason' => 'restock',
        ])->assertOk();

        InventoryTransaction::saving(function () {
            throw new RuntimeException('Simulated failure to verify inventory transaction rollback.');
        });

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", [
            'delta' => 5,
            'reason' => 'restock',
        ]);

        $response->assertStatus(500);
        // The quantity update must have rolled back along with the ledger
        // insert — still 10, not 15.
        $this->assertDatabaseHas('inventory', ['product_variant_id' => $variant->id, 'quantity_on_hand' => 10]);
        $this->assertSame(1, InventoryTransaction::where('product_variant_id', $variant->id)->count());
    }

    public function test_deleted_product_variant_inventory_is_unreachable(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $variant->delete();

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory");

        $response->assertStatus(404);
    }

    public function test_unauthenticated_requests_are_rejected_on_every_inventory_route(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $this->getJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory")->assertStatus(401);
        $this->postJson("/api/stores/{$store->id}/variants/{$variant->id}/inventory/adjust", ['delta' => 1, 'reason' => 'restock'])->assertStatus(401);
    }
}
