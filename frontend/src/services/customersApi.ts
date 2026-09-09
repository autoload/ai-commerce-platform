import { apiRequest } from './apiClient'

export type Customer = {
  id: number
  store_id: number
  name: string
  email: string
  phone: string | null
  created_at: string | null
  order_count: number
  total_spent: string
}

type CustomerListMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

type CustomerListResponse = {
  data: Customer[]
  meta: CustomerListMeta
}

type CustomerResponse = {
  data: Customer
}

export type CustomerListParams = {
  search?: string
  page?: number
}

export function listCustomers(token: string, storeId: number, params: CustomerListParams = {}) {
  const query = new URLSearchParams()
  if (params.search) query.set('search', params.search)
  if (params.page) query.set('page', String(params.page))
  const qs = query.toString()

  return apiRequest<CustomerListResponse>(`/api/stores/${storeId}/customers${qs ? `?${qs}` : ''}`, { token })
}

export function getCustomer(token: string, storeId: number, customerId: number) {
  return apiRequest<CustomerResponse>(`/api/stores/${storeId}/customers/${customerId}`, { token })
}
