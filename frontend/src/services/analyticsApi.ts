import { apiRequest } from './apiClient'

export type AnalyticsRangePreset = 'today' | 'last_7_days' | 'last_30_days' | 'this_month' | 'last_month'

export type AnalyticsRange = {
  preset: AnalyticsRangePreset
  start: string
  end: string
}

export type SalesSummary = {
  range: AnalyticsRange
  previous_range: { start: string; end: string }
  gross_sales: string
  sales_refunds: string
  net_sales: string
  order_count: number
  aov: string | null
  growth_percent: number | null
}

export type OrderStatusCounts = {
  pending: number
  paid: number
  processing: number
  shipped: number
  completed: number
  cancelled: number
  refunded: number
}

export type OrderStatusBreakdown = {
  range: AnalyticsRange
  counts: OrderStatusCounts
}

export type ProductTrendPoint = {
  date: string
  revenue: string
  quantity_sold: number
}

export type ProductAnalyticsRow = {
  product_id: number | null
  product_name: string
  revenue: string
  quantity_sold: number
  daily_trend: ProductTrendPoint[]
}

export type ProductAnalytics = {
  range: AnalyticsRange
  products: ProductAnalyticsRow[]
}

export type TopCustomer = {
  customer_id: number
  name: string
  email: string
  net_sales: string
  order_count: number
}

export type CustomerAnalytics = {
  range: AnalyticsRange
  new_customers: number
  returning_customers: number
  top_customers: TopCustomer[]
}

export type AnalyticsParams = {
  range?: AnalyticsRangePreset
}

export type ProductAnalyticsParams = AnalyticsParams & {
  sort?: 'revenue' | 'quantity'
  limit?: number
}

export type CustomerAnalyticsParams = AnalyticsParams & {
  limit?: number
}

function toQueryString(params: Record<string, string | number | undefined>): string {
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) query.set(key, String(value))
  }
  const qs = query.toString()
  return qs ? `?${qs}` : ''
}

export function getSalesSummary(token: string, storeId: number, params: AnalyticsParams = {}) {
  const qs = toQueryString({ range: params.range })
  return apiRequest<{ data: SalesSummary }>(`/api/stores/${storeId}/analytics/sales${qs}`, { token })
}

export function getOrderStatusBreakdown(token: string, storeId: number, params: AnalyticsParams = {}) {
  const qs = toQueryString({ range: params.range })
  return apiRequest<{ data: OrderStatusBreakdown }>(`/api/stores/${storeId}/analytics/orders${qs}`, { token })
}

export function getProductAnalytics(token: string, storeId: number, params: ProductAnalyticsParams = {}) {
  const qs = toQueryString({ range: params.range, sort: params.sort, limit: params.limit })
  return apiRequest<{ data: ProductAnalytics }>(`/api/stores/${storeId}/analytics/products${qs}`, { token })
}

export function getCustomerAnalytics(token: string, storeId: number, params: CustomerAnalyticsParams = {}) {
  const qs = toQueryString({ range: params.range, limit: params.limit })
  return apiRequest<{ data: CustomerAnalytics }>(`/api/stores/${storeId}/analytics/customers${qs}`, { token })
}
