<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standard JSON representation of a Customer, following
 * CategoryResource/OrderResource's flat convention. Deliberately excludes
 * password (already #[Hidden] on the model regardless), stripe_customer_id,
 * email_verified_at, organization_id, deleted_at, addresses, payment
 * methods, orders, and refunds — none of those are in the approved
 * Phase 9C detail scope.
 *
 * order_count/total_spent are NOT model attributes — they are expected to
 * arrive via withCount('orders')/withSum(['orders as total_spent' => ...],
 * 'total') on the query that produced this model (CustomerController).
 * total_spent is a raw aggregate value (not routed through Order's own
 * 'decimal:2' cast), so it is explicitly formatted here to the same
 * two-decimal string shape ("0.00", "125.50") the rest of this API uses
 * for money.
 *
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'created_at' => $this->created_at?->toIso8601String(),
            'order_count' => (int) $this->orders_count,
            'total_spent' => number_format((float) ($this->total_spent ?? 0), 2, '.', ''),
        ];
    }
}
