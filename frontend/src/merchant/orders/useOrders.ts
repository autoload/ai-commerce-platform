import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  getOrder,
  listOrders,
  updateOrderStatus,
  type MerchantOrderStatus,
  type OrderListParams,
} from '../../services/ordersApi'
import { useMerchantAuth } from '../auth/MerchantAuthContext'

const ordersKey = (storeId: number) => ['stores', storeId, 'orders'] as const

export function useOrdersList(storeId: number, params: OrderListParams = {}) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...ordersKey(storeId), 'list', params],
    queryFn: () => listOrders(token as string, storeId, params),
    enabled: token !== null,
  })
}

export function useOrder(storeId: number, orderId: number) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...ordersKey(storeId), 'detail', orderId],
    queryFn: () => getOrder(token as string, storeId, orderId),
    enabled: token !== null,
    retry: false,
  })
}

export function useUpdateOrderStatus(storeId: number, orderId: number) {
  const { token } = useMerchantAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (status: MerchantOrderStatus) => updateOrderStatus(token as string, storeId, orderId, status),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ordersKey(storeId) })
    },
  })
}
