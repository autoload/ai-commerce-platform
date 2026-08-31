import { apiRequest } from './apiClient'

export type OrderStatus = 'pending' | 'paid' | 'processing' | 'shipped' | 'completed' | 'cancelled' | 'refunded'

/** The only status values a merchant may ever set via PATCH /status. */
export type MerchantOrderStatus = 'cancelled' | 'processing' | 'shipped' | 'completed'

export type OrderItem = {
  id: number
  product_id: number | null
  product_variant_id: number | null
  product_name: string
  sku: string
  unit_price: string
  quantity: number
  line_total: string
}

export type OrderShippingAddress = {
  recipient_name: string
  line1: string
  line2: string | null
  city: string
  state: string
  postal_code: string
  country: string
  phone: string | null
}

export type Order = {
  id: number
  store_id: number
  order_number: string
  status: OrderStatus
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
  // Present on detail responses only (index() doesn't eager-load either
  // relation) — see OrderResource's whenLoaded() convention.
  items?: OrderItem[]
  shipping_address?: OrderShippingAddress | null
}

type OrderListMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

type OrderListResponse = {
  data: Order[]
  meta: OrderListMeta
}

type OrderResponse = {
  data: Order
}

export type OrderListParams = {
  status?: OrderStatus
  page?: number
}

export function listOrders(token: string, storeId: number, params: OrderListParams = {}) {
  const query = new URLSearchParams()
  if (params.status) query.set('status', params.status)
  if (params.page) query.set('page', String(params.page))
  const qs = query.toString()

  return apiRequest<OrderListResponse>(`/api/stores/${storeId}/orders${qs ? `?${qs}` : ''}`, { token })
}

export function getOrder(token: string, storeId: number, orderId: number) {
  return apiRequest<OrderResponse>(`/api/stores/${storeId}/orders/${orderId}`, { token })
}

export function updateOrderStatus(token: string, storeId: number, orderId: number, status: MerchantOrderStatus) {
  return apiRequest<OrderResponse>(`/api/stores/${storeId}/orders/${orderId}/status`, {
    method: 'PATCH',
    body: { status },
    token,
  })
}
