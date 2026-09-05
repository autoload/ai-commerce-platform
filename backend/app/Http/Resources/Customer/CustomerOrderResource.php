<?php

namespace App\Http\Resources\Customer;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing Order representation — deliberately distinct from
 * App\Http\Resources\OrderResource (the merchant/admin shape). Omits
 * organization_id, customer_id, store_id, and every internal/admin-only
 * field (no raw Payment/Refund rows, no Stripe identifiers). `items` and
 * `shipping_address` are populated via whenLoaded() exactly like
 * OrderResource's own convention — index() doesn't eager-load them, show()
 * does. `payment_status` is derived from Order::latestPayment() (never a
 * stored column) and requires callers to eager-load that relation; it's
 * null only if no Payment row exists yet for this order, which shouldn't
 * happen for any order reachable through checkout.
 *
 * @mixin Order
 */
class CustomerOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'currency' => $this->currency,
            'payment_status' => $this->whenLoaded('latestPayment', fn () => $this->latestPayment?->status->value),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'selected_options' => $item->selected_options,
            ])),
            'shipping_address' => $this->whenLoaded('addresses', function () {
                $address = $this->addresses->first();

                return $address ? [
                    'recipient_name' => $address->recipient_name,
                    'line1' => $address->line1,
                    'line2' => $address->line2,
                    'city' => $address->city,
                    'state' => $address->state,
                    'postal_code' => $address->postal_code,
                    'country' => $address->country,
                    'phone' => $address->phone,
                ] : null;
            }),
        ];
    }
}
