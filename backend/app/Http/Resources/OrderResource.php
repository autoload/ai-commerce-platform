<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standard JSON representation of an Order, following
 * StoreResource/ProductResource/InventoryResource's convention. A single
 * resource class for both list and detail (approved decision N4): `items`
 * and `shipping_address` are populated via whenLoaded() — index() doesn't
 * eager-load them (list stays light), show() does. No payment/refund data
 * (approved decision N8) — that's a different block's scope.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'currency' => $this->currency,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
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
