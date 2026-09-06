import { useQuery } from '@tanstack/react-query'
import { getCatalogProduct, listCatalogProducts } from '../../services/catalogApi'

const catalogKey = (storeId: number) => ['shop', storeId, 'products'] as const

// Public catalog browsing needs no auth token — unlike every merchant/admin
// query hook, these are enabled purely on having a valid storeId.
export function useCatalogProducts(storeId: number, page = 1) {
  return useQuery({
    queryKey: [...catalogKey(storeId), 'list', page],
    queryFn: () => listCatalogProducts(storeId, page),
    enabled: Number.isInteger(storeId) && storeId > 0,
  })
}

export function useCatalogProduct(storeId: number, productId: number) {
  return useQuery({
    queryKey: [...catalogKey(storeId), 'detail', productId],
    queryFn: () => getCatalogProduct(storeId, productId),
    enabled: Number.isInteger(storeId) && storeId > 0 && Number.isInteger(productId) && productId > 0,
    retry: false,
  })
}
