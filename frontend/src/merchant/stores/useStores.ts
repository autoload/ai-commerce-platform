import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createStore, deleteStore, getStore, listStores, updateStore, type StoreUpdatePayload } from '../../services/storesApi'
import { useMerchantAuth } from '../auth/MerchantAuthContext'

const storesKey = ['stores'] as const

export function useStoresList() {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...storesKey, 'list'],
    queryFn: () => listStores(token as string),
    enabled: token !== null,
  })
}

export function useStore(storeId: number) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...storesKey, 'detail', storeId],
    queryFn: () => getStore(token as string, storeId),
    enabled: token !== null,
    retry: false,
  })
}

export function useCreateStore() {
  const { token } = useMerchantAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (name: string) => createStore(token as string, name),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: storesKey })
    },
  })
}

export function useUpdateStore(storeId: number) {
  const { token } = useMerchantAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: StoreUpdatePayload) => updateStore(token as string, storeId, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: storesKey })
    },
  })
}

export function useDeleteStore() {
  const { token } = useMerchantAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (storeId: number) => deleteStore(token as string, storeId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: storesKey })
    },
  })
}
