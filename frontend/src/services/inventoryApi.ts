import { apiRequest } from './apiClient'

export type Inventory = {
  product_variant_id: number
  quantity_on_hand: number
  low_stock_threshold: number | null
  updated_at: string | null
}

type InventoryResponse = {
  data: Inventory
}

export type InventoryAdjustReason = 'restock' | 'adjustment'

export type InventoryAdjustPayload = {
  delta: number
  reason: InventoryAdjustReason
  note?: string
}

export function getInventory(token: string, storeId: number, variantId: number) {
  return apiRequest<InventoryResponse>(`/api/stores/${storeId}/variants/${variantId}/inventory`, { token })
}

export function adjustInventory(
  token: string,
  storeId: number,
  variantId: number,
  payload: InventoryAdjustPayload,
) {
  return apiRequest<InventoryResponse>(`/api/stores/${storeId}/variants/${variantId}/inventory/adjust`, {
    method: 'POST',
    body: payload,
    token,
  })
}
