import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import { useCatalogProduct } from './useCatalog'

export function ProductDetailPage() {
  const params = useParams<{ storeId: string; productId: string }>()
  const storeId = Number(params.storeId)
  const productId = Number(params.productId)

  const { data, isLoading, isError, error } = useCatalogProduct(storeId, productId)

  if (isLoading) {
    return <p className="text-sm text-slate-500 dark:text-slate-400">Loading product…</p>
  }

  if (isError || !data) {
    const status = error instanceof ApiError ? error.status : null
    const message =
      status === 404
        ? 'Product not found.'
        : error instanceof ApiError
          ? error.message
          : 'Unable to load this product.'

    return (
      <div className="space-y-4">
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {message}
        </p>
        <Link
          to={`/store/${storeId}/products`}
          className="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
        >
          ← Back to products
        </Link>
      </div>
    )
  }

  const product = data.data
  const primaryImage = product.images.find((image) => image.is_primary) ?? product.images[0]

  return (
    <div className="space-y-6">
      <Link
        to={`/store/${storeId}/products`}
        className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
      >
        ← Back to products
      </Link>

      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
        <div className="flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-800">
          {primaryImage ? (
            <img src={primaryImage.url} alt={product.name} className="h-full w-full object-cover" />
          ) : (
            <span className="text-sm text-slate-400 dark:text-slate-500">No image</span>
          )}
        </div>

        <div>
          {product.category && (
            <p className="text-xs font-medium text-slate-500 dark:text-slate-400">{product.category.name}</p>
          )}
          <h1 className="mt-1 text-xl font-semibold text-slate-900 dark:text-slate-50">{product.name}</h1>
          {product.description && (
            <p className="mt-3 text-sm text-slate-600 dark:text-slate-300">{product.description}</p>
          )}

          <div className="mt-6 space-y-3">
            {product.variants.length === 0 && (
              <p className="text-sm text-slate-500 dark:text-slate-400">
                This product is not currently available.
              </p>
            )}

            {product.variants.map((variant) => (
              <div
                key={variant.id}
                className="rounded-lg border border-slate-200 p-4 dark:border-slate-800"
              >
                <div className="flex items-start justify-between gap-4">
                  <div>
                    {variant.options.length > 0 && (
                      <p className="text-sm font-medium text-slate-900 dark:text-slate-100">
                        {variant.options.map((option) => `${option.option}: ${option.value}`).join(', ')}
                      </p>
                    )}
                    <p className="text-xs text-slate-500 dark:text-slate-400">SKU {variant.sku}</p>
                  </div>
                  <span
                    className={
                      variant.in_stock
                        ? 'shrink-0 text-xs font-medium text-emerald-600 dark:text-emerald-400'
                        : 'shrink-0 text-xs font-medium text-slate-400 dark:text-slate-500'
                    }
                  >
                    {variant.in_stock ? 'In stock' : 'Out of stock'}
                  </span>
                </div>
                <div className="mt-2 flex items-baseline gap-2">
                  <span className="text-lg font-semibold text-slate-900 dark:text-slate-50">${variant.price}</span>
                  {variant.compare_at_price && (
                    <span className="text-sm text-slate-400 line-through dark:text-slate-500">
                      ${variant.compare_at_price}
                    </span>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}
