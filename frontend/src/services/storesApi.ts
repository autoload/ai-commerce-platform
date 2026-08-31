import { apiRequest } from './apiClient'

export type Store = {
  id: number
  organization_id: number
  name: string
  slug: string
  status: 'active' | 'inactive'
  created_at: string | null
  updated_at: string | null
}

export type StoreListMeta = {
  current_page: number
  last_page: number
  total: number
  per_page: number
}

type StoreListResponse = {
  data: Store[]
  meta?: StoreListMeta
}

type StoreResponse = {
  data: Store
}

export type StoreUpdatePayload = {
  name?: string
  status?: 'active' | 'inactive'
}

export function listStores(token: string) {
  return apiRequest<StoreListResponse>('/api/stores', { token })
}

export function getStore(token: string, storeId: number) {
  return apiRequest<StoreResponse>(`/api/stores/${storeId}`, { token })
}

export function createStore(token: string, name: string) {
  return apiRequest<StoreResponse>('/api/stores', {
    method: 'POST',
    body: { name },
    token,
  })
}

export function updateStore(token: string, storeId: number, payload: StoreUpdatePayload) {
  return apiRequest<StoreResponse>(`/api/stores/${storeId}`, {
    method: 'PATCH',
    body: payload,
    token,
  })
}

export function deleteStore(token: string, storeId: number) {
  return apiRequest<{ message: string }>(`/api/stores/${storeId}`, {
    method: 'DELETE',
    token,
  })
}
