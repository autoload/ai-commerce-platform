import { apiRequest } from './apiClient'

export type ProductVariant = {
  id: number
  sku: string
  price: string
  compare_at_price: string | null
  status: 'draft' | 'active' | 'archived'
}

export type Product = {
  id: number
  store_id: number
  category_id: number | null
  name: string
  slug: string
  description: string | null
  status: 'draft' | 'active' | 'archived'
  variant: ProductVariant | null
  created_at: string | null
  updated_at: string | null
}

type ProductListResponse = {
  data: Product[]
}

type ProductResponse = {
  data: Product
}

export type ProductCreatePayload = {
  name: string
  description?: string
  category_id?: number
  status?: 'draft' | 'active' | 'archived'
  sku: string
  price: number
  compare_at_price?: number
}

export type ProductUpdatePayload = {
  name?: string
  description?: string
  category_id?: number | null
  status?: 'draft' | 'active' | 'archived'
  sku?: string
  price?: number
  compare_at_price?: number | null
}

export function listProducts(token: string, storeId: number) {
  return apiRequest<ProductListResponse>(`/api/stores/${storeId}/products`, { token })
}

export function getProduct(token: string, storeId: number, productId: number) {
  return apiRequest<ProductResponse>(`/api/stores/${storeId}/products/${productId}`, { token })
}

export function createProduct(token: string, storeId: number, payload: ProductCreatePayload) {
  return apiRequest<ProductResponse>(`/api/stores/${storeId}/products`, {
    method: 'POST',
    body: payload,
    token,
  })
}

export function updateProduct(token: string, storeId: number, productId: number, payload: ProductUpdatePayload) {
  return apiRequest<ProductResponse>(`/api/stores/${storeId}/products/${productId}`, {
    method: 'PATCH',
    body: payload,
    token,
  })
}

export function deleteProduct(token: string, storeId: number, productId: number) {
  return apiRequest<{ message: string }>(`/api/stores/${storeId}/products/${productId}`, {
    method: 'DELETE',
    token,
  })
}
