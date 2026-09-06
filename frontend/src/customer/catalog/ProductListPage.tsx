import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Card } from '../../components/Card'
import { ApiError } from '../../services/apiClient'
import type { CatalogProduct } from '../../services/catalogApi'
import { useCatalogProducts } from './useCatalog'

function priceDisplay(product: CatalogProduct): string {
  if (product.variants.length === 0) return '—'
  const prices = product.variants.map((variant) => Number(variant.price))
  const min = Math.min(...prices)
  const max = Math.max(...prices)
  return min === max ? `$${min.toFixed(2)}` : `$${min.toFixed(2)} – $${max.toFixed(2)}`
}

function isAvailable(product: CatalogProduct): boolean {
  return product.variants.some((variant) => variant.in_stock)
}

export function ProductListPage() {
  const params = useParams<{ storeId: string }>()
  const storeId = Number(params.storeId)
  const [page, setPage] = useState(1)

  const { data, isLoading, isError, error } = useCatalogProducts(storeId, page)

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Products</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Browse everything this store has to offer.</p>
      </div>

      {isLoading && <p className="text-sm text-slate-500 dark:text-slate-400">Loading products…</p>}

      {isError && (
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {error instanceof ApiError ? error.message : 'Unable to load products.'}
        </p>
      )}

      {data && data.data.length === 0 && (
        <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
          No products are available right now.
        </div>
      )}

      {data && data.data.length > 0 && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {data.data.map((product) => {
            const primaryImage = product.images.find((image) => image.is_primary) ?? product.images[0]
            const available = isAvailable(product)

            return (
              <Link key={product.id} to={`/store/${storeId}/products/${product.id}`}>
                <Card>
                  <div className="mb-3 flex aspect-square items-center justify-center overflow-hidden rounded-md bg-slate-100 dark:bg-slate-800">
                    {primaryImage ? (
                      <img src={primaryImage.url} alt={product.name} className="h-full w-full object-cover" />
                    ) : (
                      <span className="text-xs text-slate-400 dark:text-slate-500">No image</span>
                    )}
                  </div>
                  <p className="text-sm font-medium text-slate-900 dark:text-slate-100">{product.name}</p>
                  {product.category && (
                    <p className="text-xs text-slate-500 dark:text-slate-400">{product.category.name}</p>
                  )}
                  <div className="mt-2 flex items-center justify-between">
                    <span className="text-sm text-slate-900 dark:text-slate-100">{priceDisplay(product)}</span>
                    <span
                      className={
                        available
                          ? 'text-xs font-medium text-emerald-600 dark:text-emerald-400'
                          : 'text-xs font-medium text-slate-400 dark:text-slate-500'
                      }
                    >
                      {available ? 'In stock' : 'Out of stock'}
                    </span>
                  </div>
                </Card>
              </Link>
            )
          })}
        </div>
      )}

      {data && data.meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-slate-500 dark:text-slate-400">
          <button
            type="button"
            onClick={() => setPage((current) => Math.max(1, current - 1))}
            disabled={data.meta.current_page <= 1}
            className="rounded-md border border-slate-300 px-3 py-1.5 font-medium transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
          >
            Previous
          </button>
          <span>
            Page {data.meta.current_page} of {data.meta.last_page}
          </span>
          <button
            type="button"
            onClick={() => setPage((current) => Math.min(data.meta.last_page, current + 1))}
            disabled={data.meta.current_page >= data.meta.last_page}
            className="rounded-md border border-slate-300 px-3 py-1.5 font-medium transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
          >
            Next
          </button>
        </div>
      )}
    </div>
  )
}
