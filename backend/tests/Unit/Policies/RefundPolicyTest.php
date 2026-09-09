<?php

namespace Tests\Unit\Policies;

use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Store;
use App\Policies\RefundPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\TestCase;

class RefundPolicyTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    private RefundPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new RefundPolicy;
    }

    private function paidOrder(Store $store): Order
    {
        $order = Order::factory()->forStore($store)->create();
        $order->status = OrderStatus::Paid;
        $order->save();

        return $order;
    }

    private function refundFor(Order $order): Refund
    {
        $payment = Payment::factory()->forOrder($order)->create();
        $payment->status = PaymentStatus::Succeeded;
        $payment->save();

        $refund = new Refund;
        $refund->organization_id = $order->organization_id;
        $refund->store_id = $order->store_id;
        $refund->order_id = $order->id;
        $refund->payment_id = $payment->id;
        $refund->stripe_refund_id = 're_test_'.uniqid();
        $refund->amount = $payment->amount;
        $refund->status = RefundStatus::Pending;
        $refund->save();

        return $refund;
    }

    public function test_owner_can_view_and_create_refunds_in_any_store_of_their_active_organization(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $refund = $this->refundFor($order);

        $this->assertTrue($this->policy->view($owner, $refund));
        $this->assertTrue($this->policy->create($owner, $order));
    }

    public function test_owner_has_implicit_access_without_a_store_user_row(): void
    {
        $org = $this->activeOrganization();
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);

        $this->assertTrue($this->policy->create($owner, $order));
    }

    public function test_store_admin_can_view_and_create_refunds_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $store);
        $order = $this->paidOrder($store);
        $refund = $this->refundFor($order);

        $this->assertTrue($this->policy->view($storeAdmin, $refund));
        $this->assertTrue($this->policy->create($storeAdmin, $order));
    }

    public function test_store_admin_cannot_view_or_create_refunds_on_an_unassigned_store(): void
    {
        $org = $this->activeOrganization();
        $storeAdmin = $this->memberWithRole($org, OrganizationRole::StoreAdmin);
        $assignedStore = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($storeAdmin, $assignedStore);
        $otherStore = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($otherStore);
        $refund = $this->refundFor($order);

        $this->assertFalse($this->policy->view($storeAdmin, $refund));
        $this->assertFalse($this->policy->create($storeAdmin, $order));
    }

    public function test_staff_can_view_but_not_create_refunds_on_their_assigned_store(): void
    {
        $org = $this->activeOrganization();
        $staff = $this->memberWithRole($org, OrganizationRole::Staff);
        $store = Store::factory()->forOrganization($org)->create();
        $this->attachToStore($staff, $store);
        $order = $this->paidOrder($store);
        $refund = $this->refundFor($order);

        $this->assertTrue($this->policy->view($staff, $refund));
        $this->assertFalse($this->policy->create($staff, $order));
    }

    public function test_a_user_from_another_organization_is_denied_entirely(): void
    {
        $orgA = $this->activeOrganization();
        $orgB = $this->activeOrganization();
        $ownerOfB = $this->memberWithRole($orgB, OrganizationRole::Owner);
        $storeInA = Store::factory()->forOrganization($orgA)->create();
        $orderInA = $this->paidOrder($storeInA);
        $refundInA = $this->refundFor($orderInA);

        $this->assertFalse($this->policy->view($ownerOfB, $refundInA));
        $this->assertFalse($this->policy->create($ownerOfB, $orderInA));
    }

    public function test_owner_cannot_create_a_refund_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);

        $this->assertFalse($this->policy->create($owner, $order));
    }

    public function test_owner_can_still_view_refunds_while_organization_is_pending(): void
    {
        $org = Organization::factory()->create();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $owner = $this->memberWithRole($org, OrganizationRole::Owner);
        $store = Store::factory()->forOrganization($org)->create();
        $order = $this->paidOrder($store);
        $refund = $this->refundFor($order);

        $this->assertTrue($this->policy->view($owner, $refund));
    }
}
