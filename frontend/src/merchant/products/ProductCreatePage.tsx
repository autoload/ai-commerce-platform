import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import { useMerchantAuth } from '../auth/MerchantAuthContext'
import { useCreateProduct } from './useProducts'

export function ProductCreatePage() {
  const params = useParams<{ storeId: string }>()
  const storeId = Number(params.storeId)
  const { role, organization } = useMerchantAuth()
  const navigate = useNavigate()
  const createProduct = useCreateProduct(storeId)

  const [name, setName] = useState('')
  const [sku, setSku] = useState('')
  const [price, setPrice] = useState('')
  const [description, setDescription] = useState('')
  const [validationError, setValidationError] = useState<string | null>(null)

  const canCreate = role === 'owner' || role === 'store_admin'

  if (!canCreate) {
    return (
      <div className="space-y-4">
        <p className="text-sm text-slate-600 dark:text-slate-300">
          You do not have permission to create products in this store.
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

  if (organization && organization.status !== 'active') {
    return (
      <div className="space-y-4">
        <p className="text-sm text-slate-600 dark:text-slate-300">
          Your organization must be approved before you can create products. Current status:{' '}
          <span className="font-medium">{organization.status}</span>.
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

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setValidationError(null)

    if (!name || !sku || !price) {
      setValidationError('Name, SKU, and price are required.')
      return
    }
    const priceValue = Number(price)
    if (Number.isNaN(priceValue) || priceValue < 0) {
      setValidationError('Price must be a non-negative number.')
      return
    }

    try {
      const { data: product } = await createProduct.mutateAsync({
        name,
        sku,
        price: priceValue,
        description: description || undefined,
      })
      navigate(`/merchant/stores/${storeId}/products/${product.id}`, { replace: true })
    } catch {
      // Server-side failure message is already surfaced via mutationError below.
    }
  }

  const mutationError = createProduct.error instanceof ApiError ? createProduct.error.message : null
  const errorMessage = validationError ?? mutationError

  return (
    <div className="space-y-6">
      <Link
        to={`/merchant/stores/${storeId}/products`}
        className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
      >
        ← Back to products
      </Link>

      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Create product</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          A URL-friendly slug will be generated from the name automatically.
        </p>
      </div>

      <form className="max-w-sm space-y-4" onSubmit={handleSubmit} noValidate>
        <div>
          <label htmlFor="name" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
            Product name
          </label>
          <input
            id="name"
            name="name"
            type="text"
            value={name}
            onChange={(event) => setName(event.target.value)}
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
            value={description}
            onChange={(event) => setDescription(event.target.value)}
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
            value={sku}
            onChange={(event) => setSku(event.target.value)}
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
            value={price}
            onChange={(event) => setPrice(event.target.value)}
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
          />
        </div>

        {errorMessage && (
          <p role="alert" className="text-sm text-red-600 dark:text-red-400">
            {errorMessage}
          </p>
        )}

        <div className="flex items-center gap-3">
          <button
            type="submit"
            disabled={createProduct.isPending}
            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {createProduct.isPending ? 'Creating…' : 'Create product'}
          </button>
          <Link
            to={`/merchant/stores/${storeId}/products`}
            className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
          >
            Cancel
          </Link>
        </div>
      </form>
    </div>
  )
}
