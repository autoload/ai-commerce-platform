import { apiRequest } from './apiClient'
import type { Refund } from './ordersApi'

type RefundResponse = {
  data: Refund
}

export type RefundCreatePayload = {
  idempotency_key: string
  reason?: string
}

export function createRefund(token: string, storeId: number, orderId: number, payload: RefundCreatePayload) {
  return apiRequest<RefundResponse>(`/api/stores/${storeId}/orders/${orderId}/refund`, {
    method: 'POST',
    body: payload,
    token,
  })
}
