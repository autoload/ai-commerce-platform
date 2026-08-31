<?php

namespace App\Http\Resources;

use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standard JSON representation of an Inventory row, following
 * StoreResource/ProductResource's convention. May wrap either a real,
 * persisted Inventory row or a transient, unsaved one representing a
 * variant that has never been adjusted (quantity_on_hand: 0) — see
 * InventoryController — both expose the same attributes either way.
 *
 * @mixin Inventory
 */
class InventoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_variant_id' => $this->product_variant_id,
            'quantity_on_hand' => $this->quantity_on_hand,
            'low_stock_threshold' => $this->low_stock_threshold,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
