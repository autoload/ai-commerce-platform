<?php

namespace Tests\Feature\Merchant;

use App\Enums\OrganizationRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    // ---- List --------------------------------------------------------

    public function test_owner_can_list_categories_ordered_by_sort_order_then_name(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        Category::factory()->forStore($store)->create(['name' => 'Zebra', 'sort_order' => 1]);
        Category::factory()->forStore($store)->create(['name' => 'Apple', 'sort_order' => 1]);
        Category::factory()->forStore($store)->create(['name' => 'Widgets', 'sort_order' => 0]);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/categories");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Widgets', 'Apple', 'Zebra'], $names);
    }

    public function test_list_only_includes_categories_for_the_current_store(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($org)->create();
        $storeB = Store::factory()->forOrganization($org)->create();
        Category::factory()->forStore($storeA)->create(['name' => 'In A']);
        Category::factory()->forStore($storeB)->create(['name' => 'In B']);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$storeA->id}/categories");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'In A');
    }

    // ---- Create --------------------------------------------------------

    public function test_owner_can_create_a_category(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/categories", [
            'name' => 'Outdoor Gear',
            'description' => 'For camping and hiking.',
            'sort_order' => 5,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Outdoor Gear')
            ->assertJsonPath('data.slug', 'outdoor-gear')
            ->assertJsonPath('data.description', 'For camping and hiking.')
            ->assertJsonPath('data.sort_order', 5)
            ->assertJsonPath('data.store_id', $store->id);

        $this->assertDatabaseHas('categories', ['store_id' => $store->id, 'name' => 'Outdoor Gear']);
    }

    public function test_category_creation_defaults_sort_order_to_zero_when_omitted(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/categories", [
            'name' => 'Widgets',
        ]);

        $response->assertCreated()->assertJsonPath('data.sort_order', 0);
    }

    public function test_category_creation_generates_unique_slug_on_collision_within_the_same_store(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        Category::factory()->forStore($store)->create(['slug' => 'widgets']);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/categories", [
            'name' => 'Widgets',
        ]);

        $response->assertCreated()->assertJsonPath('data.slug', 'widgets-2');
    }

    public function test_the_same_name_is_allowed_across_different_stores(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $storeA = Store::factory()->forOrganization($org)->create();
        $storeB = Store::factory()->forOrganization($org)->create();
        Category::factory()->forStore($storeA)->create(['slug' => 'widgets']);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$storeB->id}/categories", [
            'name' => 'Widgets',
        ]);

        $response->assertCreated()->assertJsonPath('data.slug', 'widgets');
    }

    public function test_create_requires_a_name(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/categories", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_create_accepts_sort_order_as_an_integer(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/stores/{$store->id}/categories", [
            'name' => 'Widgets',
            'sort_order' => 'not-a-number',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['sort_order']);
    }

    // ---- Detail --------------------------------------------------------

    public function test_owner_can_view_category_detail(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/stores/{$store->id}/categories/{$category->id}");

        $response->assertOk()->assertJsonPath('data.id', $category->id);
    }

    public function test_nonexistent_category_returns_404(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/categories/999999")->assertStatus(404);
    }

    // ---- Update --------------------------------------------------------

    public function test_owner_can_partially_update_a_category(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($store)->create(['name' => 'Old Name', 'sort_order' => 1]);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->patchJson("/api/stores/{$store->id}/categories/{$category->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.sort_order', 1);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'New Name', 'sort_order' => 1]);
    }

    public function test_update_does_not_change_the_slug(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($store)->create(['name' => 'Original', 'slug' => 'original']);
        $token = $owner->createToken('t')->plainTextToken;

        $this->withToken($token)->patchJson("/api/stores/{$store->id}/categories/{$category->id}", [
            'name' => 'Renamed',
        ])->assertOk();

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'slug' => 'original']);
    }

    // ---- Delete: success -------------------------------------------------

    public function test_owner_can_delete_a_category_with_no_referencing_products(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($store)->create();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/stores/{$store->id}/categories/{$category->id}");

        $response->assertOk();
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    // ---- Delete: dependency protection (required scenarios) ------------

    public function test_delete_is_blocked_while_an_active_product_references_the_category(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->forStore($store)->create(['category_id' => $category->id]);
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/stores/{$store->id}/categories/{$category->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'category_id' => $category->id, 'deleted_at' => null]);
    }

    public function test_a_category_whose_only_referencing_product_is_soft_deleted_can_be_deleted(): void
    {
        // The required SoftDeletes/nullOnDelete edge case: nullOnDelete()
        // never fires on a soft-delete (an UPDATE, not a DELETE), so the
        // application-layer guard — not the FK action — is what must
        // correctly ignore an already-soft-deleted referencing product.
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->forStore($store)->create(['category_id' => $category->id]);
        $product->delete();
        $token = $owner->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/stores/{$store->id}/categories/{$category->id}");

        $response->assertOk();
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    // ---- Authorization (RBAC) -------------------------------------------

    public function test_store_admin_can_create_and_delete_categories(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $token = $storeAdmin->createToken('t')->plainTextToken;

        $create = $this->withToken($token)->postJson("/api/stores/{$store->id}/categories", ['name' => 'Widgets']);
        $create->assertCreated();

        $categoryId = $create->json('data.id');
        $this->withToken($token)->deleteJson("/api/stores/{$store->id}/categories/{$categoryId}")->assertOk();
    }

    public function test_staff_cannot_create_update_or_delete_categories(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $category = Category::factory()->forStore($store)->create();
        $token = $staff->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson("/api/stores/{$store->id}/categories", ['name' => 'Widgets'])->assertStatus(403);
        $this->withToken($token)->patchJson("/api/stores/{$store->id}/categories/{$category->id}", ['name' => 'x'])->assertStatus(403);
        $this->withToken($token)->deleteJson("/api/stores/{$store->id}/categories/{$category->id}")->assertStatus(403);
    }

    public function test_staff_can_still_list_and_view_categories(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $category = Category::factory()->forStore($store)->create();
        $token = $staff->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson("/api/stores/{$store->id}/categories")->assertOk();
        $this->withToken($token)->getJson("/api/stores/{$store->id}/categories/{$category->id}")->assertOk();
    }

    // ---- Authentication --------------------------------------------------

    public function test_unauthenticated_requests_are_rejected_on_every_category_route(): void
    {
        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $category = Category::factory()->forStore($store)->create();

        $this->getJson("/api/stores/{$store->id}/categories")->assertStatus(401);
        $this->postJson("/api/stores/{$store->id}/categories", ['name' => 'x'])->assertStatus(401);
        $this->getJson("/api/stores/{$store->id}/categories/{$category->id}")->assertStatus(401);
        $this->patchJson("/api/stores/{$store->id}/categories/{$category->id}", ['name' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/stores/{$store->id}/categories/{$category->id}")->assertStatus(401);
    }
}
