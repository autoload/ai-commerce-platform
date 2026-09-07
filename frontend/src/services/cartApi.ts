import { apiRequest } from './apiClient'

// Phase 8B revision — the authenticated-customer cart (GET/POST/PATCH/
// DELETE /api/cart[...], POST /api/cart/merge), backed server-side by
// Redis via App\Services\CartService. Mirrors catalogApi.ts/checkoutApi.ts's
// existing typed-client conventions. The server derives cart ownership
// entirely from the Bearer token (CustomerContext) — nothing here ever
// sends a customer_id/organization_id/store_id.

export type CartApiOption = { option: string; value: string }

export type CartApiItem = {
  product_id: number
  product_variant_id: number
  product_name: string
  sku: string
  price: string
  compare_at_price: string | null
  // Live stock signal, re-derived from MySQL on every read — unlike the
  // guest cart's inStockAtAdd (an add-to-cart-time snapshot), this always
  // reflects current inventory, since Redis stores no display data at all.
  in_stock: boolean
  options: CartApiOption[]
  quantity: number
  line_total: string
}

export type CartApiState = {
  items: CartApiItem[]
  subtotal: string
  currency: string
}

type CartApiResponse = {
  data: CartApiState
}

export function getCart(token: string) {
  return apiRequest<CartApiResponse>('/api/cart', { token })
}

export function addCartItem(token: string, productVariantId: number, quantity: number) {
  return apiRequest<CartApiResponse>('/api/cart/items', {
    method: 'POST',
    token,
    body: { product_variant_id: productVariantId, quantity },
  })
}

export function updateCartItem(token: string, variantId: number, quantity: number) {
  return apiRequest<CartApiResponse>(`/api/cart/items/${variantId}`, {
    method: 'PATCH',
    token,
    body: { quantity },
  })
}

export function removeCartItem(token: string, variantId: number) {
  return apiRequest<CartApiResponse>(`/api/cart/items/${variantId}`, {
    method: 'DELETE',
    token,
  })
}

// 204 No Content — apiRequest resolves it to `null`.
export function clearCart(token: string) {
  return apiRequest<null>('/api/cart', { method: 'DELETE', token })
}

export type MergeCartItemInput = { product_variant_id: number; quantity: number }

export function mergeCart(token: string, items: MergeCartItemInput[]) {
  return apiRequest<CartApiResponse>('/api/cart/merge', {
    method: 'POST',
    token,
    body: { items },
  })
}
