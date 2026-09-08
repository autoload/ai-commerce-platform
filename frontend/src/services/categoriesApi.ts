import { apiRequest } from './apiClient'

export type Category = {
  id: number
  store_id: number
  name: string
  slug: string
  description: string | null
  sort_order: number
  created_at: string | null
  updated_at: string | null
}

type CategoryListResponse = {
  data: Category[]
}

type CategoryResponse = {
  data: Category
}

export type CategoryCreatePayload = {
  name: string
  description?: string
  sort_order?: number
}

export type CategoryUpdatePayload = {
  name?: string
  description?: string
  sort_order?: number
}

export function listCategories(token: string, storeId: number) {
  return apiRequest<CategoryListResponse>(`/api/stores/${storeId}/categories`, { token })
}

export function getCategory(token: string, storeId: number, categoryId: number) {
  return apiRequest<CategoryResponse>(`/api/stores/${storeId}/categories/${categoryId}`, { token })
}

export function createCategory(token: string, storeId: number, payload: CategoryCreatePayload) {
  return apiRequest<CategoryResponse>(`/api/stores/${storeId}/categories`, {
    method: 'POST',
    body: payload,
    token,
  })
}

export function updateCategory(token: string, storeId: number, categoryId: number, payload: CategoryUpdatePayload) {
  return apiRequest<CategoryResponse>(`/api/stores/${storeId}/categories/${categoryId}`, {
    method: 'PATCH',
    body: payload,
    token,
  })
}

export function deleteCategory(token: string, storeId: number, categoryId: number) {
  return apiRequest<{ message: string }>(`/api/stores/${storeId}/categories/${categoryId}`, {
    method: 'DELETE',
    token,
  })
}
