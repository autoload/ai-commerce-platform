import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { adjustInventory, getInventory, type InventoryAdjustPayload } from '../../services/inventoryApi'
import { useMerchantAuth } from '../auth/MerchantAuthContext'

const inventoryKey = (storeId: number, variantId: number) => ['stores', storeId, 'variants', variantId, 'inventory'] as const

export function useInventory(storeId: number, variantId: number | undefined) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: variantId ? inventoryKey(storeId, variantId) : ['stores', storeId, 'variants', 'none', 'inventory'],
    queryFn: () => getInventory(token as string, storeId, variantId as number),
    enabled: token !== null && variantId !== undefined,
  })
}

export function useAdjustInventory(storeId: number, variantId: number | undefined) {
  const { token } = useMerchantAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: InventoryAdjustPayload) => adjustInventory(token as string, storeId, variantId as number, payload),
    onSuccess: () => {
      if (variantId) {
        void queryClient.invalidateQueries({ queryKey: inventoryKey(storeId, variantId) })
      }
    },
  })
}
