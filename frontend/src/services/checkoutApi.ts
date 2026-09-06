import { apiRequest } from './apiClient'

// POST /api/checkout — the existing backend checkout endpoint (STEP 3B,
// unchanged by this block). The client sends only variant ids/quantities;
// the server independently re-reads product/variant existence, store
// ownership, current price, and inventory before creating anything — see
// CheckoutController/CheckoutOrderCreationService. Nothing sent here is
// ever treated as authoritative by the backend, and the response's `data`
// (an OrderResource, the same shape the merchant order endpoints return —
// note this is NOT the customer-facing CustomerOrderResource used by
// Block 7's order-history endpoints) carries the authoritative order/total.

export type CheckoutOrderStatus =
  | 'pending'
  | 'paid'
  | 'processing'
  | 'shipped'
  | 'completed'
  | 'cancelled'
  | 'refunded'

export type CheckoutOrderItem = {
  id: number
  product_id: number | null
  product_variant_id: number | null
  product_name: string
  sku: string
  unit_price: string
  quantity: number
  line_total: string
}

export type CheckoutShippingAddress = {
  recipient_name: string
  line1: string
  line2: string | null
  city: string
  state: string
  postal_code: string
  country: string
  phone: string | null
}

export type CheckoutOrder = {
  id: number
  store_id: number
  order_number: string
  status: CheckoutOrderStatus
  status_reason: string | null
  subtotal: string
  discount_total: string
  tax_total: string
  total: string
  currency: string
  customer_name: string
  customer_email: string
  paid_at: string | null
  cancelled_at: string | null
  created_at: string | null
  updated_at: string | null
  items?: CheckoutOrderItem[]
  shipping_address?: CheckoutShippingAddress | null
}

export type CheckoutPayment = {
  client_secret: string
  stripe_payment_intent_id: string
}

export type CheckoutResponse = {
  data: CheckoutOrder
  payment: CheckoutPayment
}

export type CheckoutItemInput = {
  product_variant_id: number
  quantity: number
}

export type CheckoutAddressInput = {
  recipient_name: string
  line1: string
  line2?: string
  city: string
  state: string
  postal_code: string
  country: string
  phone?: string
}

export function checkout(
  token: string,
  idempotencyKey: string,
  items: CheckoutItemInput[],
  shippingAddress: CheckoutAddressInput,
) {
  return apiRequest<CheckoutResponse>('/api/checkout', {
    method: 'POST',
    token,
    headers: { 'Idempotency-Key': idempotencyKey },
    body: { items, shipping_address: shippingAddress },
  })
}
