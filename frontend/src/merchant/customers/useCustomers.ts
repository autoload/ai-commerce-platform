import { useQuery } from '@tanstack/react-query'
import { getCustomer, listCustomers, type CustomerListParams } from '../../services/customersApi'
import { useMerchantAuth } from '../auth/MerchantAuthContext'

const customersKey = (storeId: number) => ['stores', storeId, 'customers'] as const

export function useCustomersList(storeId: number, params: CustomerListParams = {}) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...customersKey(storeId), 'list', params],
    queryFn: () => listCustomers(token as string, storeId, params),
    enabled: token !== null,
  })
}

export function useCustomer(storeId: number, customerId: number) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...customersKey(storeId), 'detail', customerId],
    queryFn: () => getCustomer(token as string, storeId, customerId),
    enabled: token !== null,
    retry: false,
  })
}
