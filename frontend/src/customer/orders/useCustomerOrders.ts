import { useQuery } from '@tanstack/react-query'
import { getCustomerOrder, listCustomerOrders } from '../../services/customerOrdersApi'
import { useCustomerAuth } from '../auth/CustomerAuthContext'

// Distinct query-key namespace from merchant orders' ['stores', storeId,
// 'orders', ...] (see merchant/orders/useOrders.ts) — these are two
// different identity domains that could otherwise collide in the same
// browser tab (e.g. during development/testing).
const customerOrdersKey = (storeId: number) => ['customer-orders', storeId] as const

export function useCustomerOrdersList(storeId: number, page = 1) {
  const { token } = useCustomerAuth()

  return useQuery({
    queryKey: [...customerOrdersKey(storeId), 'list', page],
    queryFn: () => listCustomerOrders(token as string, page),
    enabled: token !== null,
  })
}

export function useCustomerOrder(storeId: number, orderId: number) {
  const { token } = useCustomerAuth()

  return useQuery({
    queryKey: [...customerOrdersKey(storeId), 'detail', orderId],
    queryFn: () => getCustomerOrder(token as string, orderId),
    enabled: token !== null && Number.isInteger(orderId) && orderId > 0,
    retry: false,
  })
}
