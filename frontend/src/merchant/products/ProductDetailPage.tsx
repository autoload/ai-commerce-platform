import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import { useMerchantAuth } from '../auth/MerchantAuthContext'
import { InventorySection } from './InventorySection'
import { useDeleteProduct, useProduct, useUpdateProduct } from './useProducts'

const STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  active: 'Active',
  archived: 'Archived',
}

export function ProductDetailPage() {
  const params = useParams<{ storeId: string; productId: string }>()
  const storeId = Number(params.storeId)
  const productId = Number(params.productId)
  const { role } = useMerchantAuth()
  const navigate = useNavigate()

  const { data, isLoading, isError, error } = useProduct(storeId, productId)
  const updateProduct = useUpdateProduct(storeId, productId)
  const deleteProduct = useDeleteProduct(storeId)

  const [isEditing, setIsEditing] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const canManage = role === 'owner' || role === 'store_admin'

  if (isLoading) {
    return <p className="text-sm text-slate-500 dark:text-slate-400">Loading product…</p>
  }

  if (isError || !data) {
    const status = error instanceof ApiError ? error.status : null
    const message =
      status === 404
        ? 'Product not found.'
        : status === 403
          ? 'You do not have access to this product.'
          : error instanceof ApiError
            ? error.message
            : 'Unable to load this product.'

    return (
      <div className="space-y-4">
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {message}
        </p>
        <Link
          to={`/merchant/stores/${storeId}/products`}
          className="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
        >
          ← Back to products
        </Link>
      </div>
    )
  }

  const product = data.data

  async function handleUpdate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const name = String(formData.get('name') ?? '').trim()
    const sku = String(formData.get('sku') ?? '').trim()
    const priceRaw = String(formData.get('price') ?? '').trim()
    const description = String(formData.get('description') ?? '').trim()

    if (!name || !sku || !priceRaw) {
      return
    }
    const price = Number(priceRaw)
    if (Number.isNaN(price) || price < 0) {
      return
    }

    try {
      await updateProduct.mutateAsync({ name, sku, price, description })
      setIsEditing(false)
    } catch {
      // Server-side failure message is already surfaced via updateProduct.error below.
    }
  }

  async function handleToggleStatus() {
    try {
      await updateProduct.mutateAsync({ status: product.status === 'active' ? 'draft' : 'active' })
    } catch {
      // Surfaced via updateProduct.error below.
    }
  }

  async function handleDelete() {
    try {
      await deleteProduct.mutateAsync(product.id)
      navigate(`/merchant/stores/${storeId}/products`, { replace: true })
    } catch {
      // Surfaced via deleteProduct.error below.
    }
  }

  const updateErrorMessage = updateProduct.error instanceof ApiError ? updateProduct.error.message : null
  const deleteErrorMessage = deleteProduct.error instanceof ApiError ? deleteProduct.error.message : null

  return (
    <div className="space-y-6">
      <Link
        to={`/merchant/stores/${storeId}/products`}
        className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
      >
        ← Back to products
      </Link>

      <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        {!isEditing ? (
          <div className="flex items-start justify-between">
            <div>
              <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">{product.name}</h1>
              <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{product.slug}</p>
              {product.description && (
                <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{product.description}</p>
              )}
              {product.variant && (
                <dl className="mt-4 space-y-1 text-sm">
                  <div className="flex gap-2">
                    <dt className="text-slate-500 dark:text-slate-400">SKU</dt>
                    <dd className="text-slate-900 dark:text-slate-100">{product.variant.sku}</dd>
                  </div>
                  <div className="flex gap-2">
                    <dt className="text-slate-500 dark:text-slate-400">Price</dt>
                    <dd className="text-slate-900 dark:text-slate-100">${product.variant.price}</dd>
                  </div>
                </dl>
              )}
            </div>
            <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
              {STATUS_LABELS[product.status] ?? product.status}
            </span>
          </div>
        ) : (
          <form className="space-y-3" onSubmit={handleUpdate}>
            <div>
              <label htmlFor="name" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                Product name
              </label>
              <input
                id="name"
                name="name"
                type="text"
                defaultValue={product.name}
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              />
            </div>
            <div>
              <label htmlFor="description" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                Description
              </label>
              <textarea
                id="description"
                name="description"
                rows={3}
                defaultValue={product.description ?? ''}
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              />
            </div>
            <div>
              <label htmlFor="sku" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                SKU
              </label>
              <input
                id="sku"
                name="sku"
                type="text"
                defaultValue={product.variant?.sku ?? ''}
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              />
            </div>
            <div>
              <label htmlFor="price" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                Price
              </label>
              <input
                id="price"
                name="price"
                type="number"
                step="0.01"
                min="0"
                defaultValue={product.variant?.price ?? ''}
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              />
            </div>
            <div className="flex items-center gap-3">
              <button
                type="submit"
                disabled={updateProduct.isPending}
                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {updateProduct.isPending ? 'Saving…' : 'Save'}
              </button>
              <button
                type="button"
                onClick={() => setIsEditing(false)}
                className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
              >
                Cancel
              </button>
            </div>
          </form>
        )}

        {updateErrorMessage && (
          <p role="alert" className="mt-3 text-sm text-red-600 dark:text-red-400">
            {updateErrorMessage}
          </p>
        )}

        {canManage && !isEditing && (
          <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4 dark:border-slate-800">
            <button
              type="button"
              onClick={() => setIsEditing(true)}
              className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              Edit
            </button>
            <button
              type="button"
              onClick={handleToggleStatus}
              disabled={updateProduct.isPending}
              className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              {product.status === 'active' ? 'Mark as draft' : 'Mark as active'}
            </button>

            {!confirmingDelete ? (
              <button
                type="button"
                onClick={() => setConfirmingDelete(true)}
                className="ml-auto rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950"
              >
                Delete product
              </button>
            ) : (
              <div className="ml-auto flex items-center gap-2">
                <span className="text-sm text-slate-600 dark:text-slate-300">Delete this product?</span>
                <button
                  type="button"
                  onClick={handleDelete}
                  disabled={deleteProduct.isPending}
                  className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {deleteProduct.isPending ? 'Deleting…' : 'Confirm'}
                </button>
                <button
                  type="button"
                  onClick={() => setConfirmingDelete(false)}
                  className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
                >
                  Cancel
                </button>
              </div>
            )}
          </div>
        )}

        {deleteErrorMessage && (
          <p role="alert" className="mt-3 text-sm text-red-600 dark:text-red-400">
            {deleteErrorMessage}
          </p>
        )}
      </div>

      {product.variant && (
        <InventorySection storeId={storeId} variantId={product.variant.id} canAdjust={canManage} />
      )}
    </div>
  )
}
