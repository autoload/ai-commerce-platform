import { apiRequest } from './apiClient'

// Public, unauthenticated storefront browsing — GET /api/shop/stores/{store}/products*.
// Mirrors CatalogProductResource's shape exactly; deliberately distinct from
// productsApi.ts (the merchant-facing, authenticated Product shape).

export type CatalogCategory = {
  id: number
  name: string
  slug: string
}

export type CatalogImage = {
  id: number
  url: string
  is_primary: boolean
  sort_order: number
}

export type CatalogOptionValue = {
  id: number
  value: string
}

export type CatalogOption = {
  id: number
  name: string
  values: CatalogOptionValue[]
}

export type CatalogVariantOption = {
  option: string
  value: string
}

export type CatalogVariant = {
  id: number
  sku: string
  price: string
  compare_at_price: string | null
  // Coarse availability signal only — never an exact quantity. Checkout
  // independently re-validates availability under a row lock regardless of
  // what this flag says.
  in_stock: boolean
  options: CatalogVariantOption[]
}

export type CatalogProduct = {
  id: number
  name: string
  slug: string
  description: string | null
  category: CatalogCategory | null
  images: CatalogImage[]
  options: CatalogOption[]
  variants: CatalogVariant[]
}

type CatalogProductListMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

type CatalogProductListResponse = {
  data: CatalogProduct[]
  meta: CatalogProductListMeta
}

type CatalogProductResponse = {
  data: CatalogProduct
}

export function listCatalogProducts(storeId: number, page = 1) {
  const query = new URLSearchParams()
  if (page > 1) query.set('page', String(page))
  const qs = query.toString()

  return apiRequest<CatalogProductListResponse>(`/api/shop/stores/${storeId}/products${qs ? `?${qs}` : ''}`)
}

export function getCatalogProduct(storeId: number, productId: number) {
  return apiRequest<CatalogProductResponse>(`/api/shop/stores/${storeId}/products/${productId}`)
}
