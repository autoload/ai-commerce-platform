import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createProduct,
  deleteProduct,
  getProduct,
  listProducts,
  updateProduct,
  type ProductCreatePayload,
  type ProductUpdatePayload,
} from '../../services/productsApi'
import { useMerchantAuth } from '../auth/MerchantAuthContext'

const productsKey = (storeId: number) => ['stores', storeId, 'products'] as const

export function useProductsList(storeId: number) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...productsKey(storeId), 'list'],
    queryFn: () => listProducts(token as string, storeId),
    enabled: token !== null,
  })
}

export function useProduct(storeId: number, productId: number) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...productsKey(storeId), 'detail', productId],
    queryFn: () => getProduct(token as string, storeId, productId),
    enabled: token !== null,
    retry: false,
  })
}

export function useCreateProduct(storeId: number) {
  const { token } = useMerchantAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: ProductCreatePayload) => createProduct(token as string, storeId, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: productsKey(storeId) })
    },
  })
}

export function useUpdateProduct(storeId: number, productId: number) {
  const { token } = useMerchantAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: ProductUpdatePayload) => updateProduct(token as string, storeId, productId, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: productsKey(storeId) })
    },
  })
}

export function useDeleteProduct(storeId: number) {
  const { token } = useMerchantAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (productId: number) => deleteProduct(token as string, storeId, productId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: productsKey(storeId) })
    },
  })
}
