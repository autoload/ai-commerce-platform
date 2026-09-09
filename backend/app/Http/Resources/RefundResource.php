<?php

namespace App\Http\Resources;

use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standard JSON representation of a Refund, following
 * CustomerResource/CategoryResource's flat convention. Deliberately
 * excludes stripe_refund_id (internal identifier, no established
 * precedent exposes a raw Stripe id to merchants), initiated_by_user_id,
 * and organization_id/store_id — none of those are in the approved
 * Phase 9D resource scope.
 *
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'payment_id' => $this->payment_id,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
