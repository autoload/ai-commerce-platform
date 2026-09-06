import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { QuantityStepper } from '../../components/QuantityStepper'
import { ApiError } from '../../services/apiClient'
import type { CatalogVariant } from '../../services/catalogApi'
import { useCart } from '../cart/CartContext'
import { useCatalogProduct } from './useCatalog'

function variantLabel(variant: CatalogVariant): string {
  return variant.options.map((option) => `${option.option}: ${option.value}`).join(', ')
}

export function ProductDetailPage() {
  const params = useParams<{ storeId: string; productId: string }>()
  const storeId = Number(params.storeId)
  const productId = Number(params.productId)

  const { data, isLoading, isError, error } = useCatalogProduct(storeId, productId)
  const { addItem } = useCart()

  // { productId, variantId, quantity } instead of separate state so
  // navigating to a different product (without a remount, since it's the
  // same route pattern) resets the selection during render rather than via
  // an effect — same pattern used in CustomerAuthContext/CartContext.
  // A product with exactly one variant (the common case — see
  // CatalogProductResource's docblock) is auto-selected; a product with
  // several requires an explicit click before "Add to cart" is enabled.
  const [selection, setSelection] = useState<{ productId: number; variantId: number | null; quantity: number }>({
    productId: -1,
    variantId: null,
    quantity: 1,
  })
  const [feedback, setFeedback] = useState<string | null>(null)

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

  let { variantId: selectedVariantId, quantity } = selection
  if (selection.productId !== product.id) {
    selectedVariantId = product.variants.length === 1 ? product.variants[0].id : null
    quantity = 1
    setSelection({ productId: product.id, variantId: selectedVariantId, quantity })
  }

  const selectedVariant = product.variants.find((variant) => variant.id === selectedVariantId) ?? null

  function handleAddToCart() {
    if (!selectedVariant) return

    addItem(
      {
        productId: product.id,
        variantId: selectedVariant.id,
        productName: product.name,
        variantLabel: variantLabel(selectedVariant),
        sku: selectedVariant.sku,
        displayPrice: selectedVariant.price,
        inStockAtAdd: selectedVariant.in_stock,
      },
      quantity,
    )
    setFeedback(`Added ${quantity} to your cart.`)
  }

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

            {product.variants.length > 1 && (
              <p className="text-sm font-medium text-slate-700 dark:text-slate-300">Choose an option:</p>
            )}

            {product.variants.map((variant) => {
              const isSelected = variant.id === selectedVariantId

              return (
                <button
                  key={variant.id}
                  type="button"
                  onClick={() => {
                    setSelection({ productId: product.id, variantId: variant.id, quantity: 1 })
                    setFeedback(null)
                  }}
                  className={
                    'w-full rounded-lg border p-4 text-left transition ' +
                    (isSelected
                      ? 'border-indigo-500 ring-1 ring-indigo-500'
                      : 'border-slate-200 hover:border-slate-300 dark:border-slate-800 dark:hover:border-slate-700')
                  }
                >
                  <div className="flex items-start justify-between gap-4">
                    <div>
                      {variant.options.length > 0 && (
                        <p className="text-sm font-medium text-slate-900 dark:text-slate-100">
                          {variantLabel(variant)}
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
                </button>
              )
            })}
          </div>

          {product.variants.length > 0 && (
            <div className="mt-6 space-y-3 border-t border-slate-200 pt-4 dark:border-slate-800">
              <QuantityStepper
                value={quantity}
                onIncrement={() => setSelection({ productId: product.id, variantId: selectedVariantId, quantity: quantity + 1 })}
                onDecrement={() =>
                  setSelection({
                    productId: product.id,
                    variantId: selectedVariantId,
                    quantity: Math.max(1, quantity - 1),
                  })
                }
                onChange={(next) => setSelection({ productId: product.id, variantId: selectedVariantId, quantity: next })}
              />

              <button
                type="button"
                onClick={handleAddToCart}
                disabled={!selectedVariant || !selectedVariant.in_stock}
                className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {!selectedVariant
                  ? 'Select an option'
                  : !selectedVariant.in_stock
                    ? 'Out of stock'
                    : 'Add to cart'}
              </button>

              {feedback && (
                <p role="status" className="text-sm text-emerald-600 dark:text-emerald-400">
                  {feedback}
                </p>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
