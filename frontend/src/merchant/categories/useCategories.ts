import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createCategory,
  deleteCategory,
  getCategory,
  listCategories,
  updateCategory,
  type CategoryCreatePayload,
  type CategoryUpdatePayload,
} from '../../services/categoriesApi'
import { useMerchantAuth } from '../auth/MerchantAuthContext'

const categoriesKey = (storeId: number) => ['stores', storeId, 'categories'] as const

export function useCategoriesList(storeId: number) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...categoriesKey(storeId), 'list'],
    queryFn: () => listCategories(token as string, storeId),
    enabled: token !== null,
  })
}

export function useCategory(storeId: number, categoryId: number) {
  const { token } = useMerchantAuth()

  return useQuery({
    queryKey: [...categoriesKey(storeId), 'detail', categoryId],
    queryFn: () => getCategory(token as string, storeId, categoryId),
    enabled: token !== null,
    retry: false,
  })
}

export function useCreateCategory(storeId: number) {
  const { token } = useMerchantAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: CategoryCreatePayload) => createCategory(token as string, storeId, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: categoriesKey(storeId) })
    },
  })
}

export function useUpdateCategory(storeId: number, categoryId: number) {
  const { token } = useMerchantAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: CategoryUpdatePayload) => updateCategory(token as string, storeId, categoryId, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: categoriesKey(storeId) })
    },
  })
}

export function useDeleteCategory(storeId: number) {
  const { token } = useMerchantAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (categoryId: number) => deleteCategory(token as string, storeId, categoryId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: categoriesKey(storeId) })
    },
  })
}
