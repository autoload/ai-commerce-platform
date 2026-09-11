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
 * order_count/gross_sales_amount/sales_refunds are NOT model attributes —
 * they are expected to arrive via withCount('orders')/withSum(['orders as
 * gross_sales_amount' => ...], 'total')/addSelect(['sales_refunds' => ...])
 * on the query that produced this model (CustomerController's
 * withCustomerAggregates()). total_spent is computed here as
 * gross_sales_amount − sales_refunds (Net Sales per customer, per
 * App\Support\SalesClassification's authoritative "which orders count as a
 * sale" definition) rather than exposed as a single raw aggregate — the
 * wire field name and its "0.00"/"125.50" two-decimal string shape are
 * unchanged for backward compatibility; only the underlying calculation
 * changed, to stop counting a since-refunded order's amount as spend.
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
            'total_spent' => number_format(
                (float) ($this->gross_sales_amount ?? 0) - (float) ($this->sales_refunds ?? 0),
                2, '.', ''
            ),
        ];
    }
}
