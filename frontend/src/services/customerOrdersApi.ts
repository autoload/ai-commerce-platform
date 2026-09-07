import { apiRequest } from './apiClient'

// Phase 8E — read-only client for the customer-facing order endpoints,
// which already existed and were already fully tested as of Phase 7
// (App\Http\Controllers\Customer\OrderController /
// App\Http\Resources\Customer\CustomerOrderResource). This file only adds
// a typed frontend consumer for `GET /api/customers/orders[/{order}]` — no
// new backend endpoint. Deliberately distinct from `ordersApi.ts` (the
// merchant-facing shape, a different Resource with different fields, e.g.
// no `payment_status`), the same "customer" vs. merchant separation
// `customerAuth.ts` already establishes for auth.

export type OrderStatus = 'pending' | 'paid' | 'processing' | 'shipped' | 'completed' | 'cancelled' | 'refunded'

export type PaymentStatus = 'requires_payment' | 'processing' | 'succeeded' | 'failed' | 'canceled'

export type CustomerOrderItem = {
  product_id: number | null
  product_variant_id: number | null
  product_name: string
  sku: string
  unit_price: string
  quantity: number
  line_total: string
  selected_options: Record<string, unknown> | null
}

export type CustomerOrderShippingAddress = {
  recipient_name: string
  line1: string
  line2: string | null
  city: string
  state: string
  postal_code: string
  country: string
  phone: string | null
}

export type CustomerOrder = {
  id: number
  order_number: string
  status: OrderStatus
  status_reason: string | null
  subtotal: string
  discount_total: string
  tax_total: string
  total: string
  currency: string
  // Derived from the latest Payment attempt, never a stored column — null
  // only if somehow no Payment row exists yet for this order. Present on
  // both list and detail responses.
  payment_status: PaymentStatus | null
  paid_at: string | null
  cancelled_at: string | null
  created_at: string | null
  // Present on detail responses only (index() doesn't eager-load either
  // relation) — mirrors OrderResource/CustomerOrderResource's own
  // whenLoaded() convention on the backend.
  items?: CustomerOrderItem[]
  shipping_address?: CustomerOrderShippingAddress | null
}

type CustomerOrderListMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

type CustomerOrderListResponse = {
  data: CustomerOrder[]
  meta: CustomerOrderListMeta
}

type CustomerOrderResponse = {
  data: CustomerOrder
}

export function listCustomerOrders(token: string, page = 1) {
  const query = new URLSearchParams()
  if (page > 1) query.set('page', String(page))
  const qs = query.toString()

  return apiRequest<CustomerOrderListResponse>(`/api/customers/orders${qs ? `?${qs}` : ''}`, { token })
}

export function getCustomerOrder(token: string, orderId: number) {
  return apiRequest<CustomerOrderResponse>(`/api/customers/orders/${orderId}`, { token })
}
