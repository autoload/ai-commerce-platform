import { useQuery } from '@tanstack/react-query'
import {
  getCustomerAnalytics,
  getOrderStatusBreakdown,
  getProductAnalytics,
  getSalesSummary,
  type AnalyticsRangePreset,
} from '../../services/analyticsApi'
import { useMerchantAuth } from '../auth/MerchantAuthContext'

const analyticsKey = (storeId: number) => ['stores', storeId, 'analytics'] as const

export function useSalesSummary(storeId: number, range: AnalyticsRangePreset) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...analyticsKey(storeId), 'sales', range],
    queryFn: () => getSalesSummary(token as string, storeId, { range }),
    enabled: token !== null,
  })
}

export function useOrderStatusBreakdown(storeId: number, range: AnalyticsRangePreset) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...analyticsKey(storeId), 'orders', range],
    queryFn: () => getOrderStatusBreakdown(token as string, storeId, { range }),
    enabled: token !== null,
  })
}

export function useProductAnalytics(storeId: number, range: AnalyticsRangePreset) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...analyticsKey(storeId), 'products', range],
    queryFn: () => getProductAnalytics(token as string, storeId, { range }),
    enabled: token !== null,
  })
}

export function useCustomerAnalytics(storeId: number, range: AnalyticsRangePreset) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...analyticsKey(storeId), 'customers', range],
    queryFn: () => getCustomerAnalytics(token as string, storeId, { range }),
    enabled: token !== null,
  })
}
