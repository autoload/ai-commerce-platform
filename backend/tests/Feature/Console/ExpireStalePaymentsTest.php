<?php

namespace Tests\Feature\Console;

use App\Enums\CatalogStatus;
use App\Enums\InventoryTransactionReason;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\InventoryAdjustmentService;
use App\Services\StripePaymentIntentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenantFixtures;
use Tests\Doubles\FakePaymentIntentGateway;
use Tests\TestCase;

/**
 * Phase 6 — the thin `payments:expire-stale` Artisan command wrapping
 * PaymentExpirySweepService (unit-tested directly and exhaustively in
 * PaymentExpirySweepServiceTest). This test only proves the command wires
 * up correctly and reports its outcome — not the sweep's own business
 * rules again.
 */
class ExpireStalePaymentsTest extends TestCase
{
    use CreatesTenantFixtures, RefreshDatabase;

    public function test_command_runs_the_sweep_and_reports_a_summary(): void
    {
        $fake = new FakePaymentIntentGateway;
        $this->app->instance(StripePaymentIntentGateway::class, $fake);
        config(['services.stripe.checkout_expiry_minutes' => 30]);

        $org = $this->activeOrganization();
        $store = Store::factory()->forOrganization($org)->create();
        $customer = Customer::factory()->forStore($store)->create();
        $product = Product::factory()->forStore($store)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'price' => 20.00,
            'status' => CatalogStatus::Active,
        ]);
        app(InventoryAdjustmentService::class)->adjust($variant, 10, InventoryTransactionReason::Restock, null, null);

        $order = Order::factory()->forStore($store)->create();
        $order->customer_id = $customer->id;
        $order->customer_name = $customer->name;
        $order->customer_email = $customer->email;
        $order->subtotal = 20.00;
        $order->total = 20.00;
        $order->save();

        $item = OrderItem::factory()->forOrder($order)->create();
        $item->product_id = $variant->product_id;
        $item->product_variant_id = $variant->id;
        $item->unit_price = 20.00;
        $item->quantity = 1;
        $item->line_total = 20.00;
        $item->save();

        $this->travelTo(now()->subMinutes(45));
        $payment = Payment::factory()->forOrder($order)->create();
        app(InventoryAdjustmentService::class)->adjust(
            $variant, -1, InventoryTransactionReason::Checkout, null, null, $item, $payment
        );
        $this->travelBack();

        $this->artisan('payments:expire-stale')
            ->expectsOutputToContain('1 processed, 1 cancelled, 0 deferred, 0 skipped, 0 errors')
            ->assertExitCode(0);

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame('expired', $order->status_reason);
    }
}
