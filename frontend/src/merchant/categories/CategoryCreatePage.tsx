import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import { useMerchantAuth } from '../auth/MerchantAuthContext'
import { useCreateCategory } from './useCategories'

export function CategoryCreatePage() {
  const params = useParams<{ storeId: string }>()
  const storeId = Number(params.storeId)
  const { role, organization } = useMerchantAuth()
  const navigate = useNavigate()
  const createCategory = useCreateCategory(storeId)

  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [sortOrder, setSortOrder] = useState('')
  const [validationError, setValidationError] = useState<string | null>(null)

  const canCreate = role === 'owner' || role === 'store_admin'

  if (!canCreate) {
    return (
      <div className="space-y-4">
        <p className="text-sm text-slate-600 dark:text-slate-300">
          You do not have permission to create categories in this store.
        </p>
        <Link
          to={`/merchant/stores/${storeId}/categories`}
          className="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
        >
          ← Back to categories
        </Link>
      </div>
    )
  }

  if (organization && organization.status !== 'active') {
    return (
      <div className="space-y-4">
        <p className="text-sm text-slate-600 dark:text-slate-300">
          Your organization must be approved before you can create categories. Current status:{' '}
          <span className="font-medium">{organization.status}</span>.
        </p>
        <Link
          to={`/merchant/stores/${storeId}/categories`}
          className="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
        >
          ← Back to categories
        </Link>
      </div>
    )
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setValidationError(null)

    if (!name) {
      setValidationError('Name is required.')
      return
    }
    let sortOrderValue: number | undefined
    if (sortOrder !== '') {
      sortOrderValue = Number(sortOrder)
      if (!Number.isInteger(sortOrderValue)) {
        setValidationError('Sort order must be a whole number.')
        return
      }
    }

    try {
      const { data: category } = await createCategory.mutateAsync({
        name,
        description: description || undefined,
        sort_order: sortOrderValue,
      })
      navigate(`/merchant/stores/${storeId}/categories/${category.id}`, { replace: true })
    } catch {
      // Server-side failure message is already surfaced via mutationError below.
    }
  }

  const mutationError = createCategory.error instanceof ApiError ? createCategory.error.message : null
  const errorMessage = validationError ?? mutationError

  return (
    <div className="space-y-6">
      <Link
        to={`/merchant/stores/${storeId}/categories`}
        className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
      >
        ← Back to categories
      </Link>

      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Create category</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          A URL-friendly slug will be generated from the name automatically.
        </p>
      </div>

      <form className="max-w-sm space-y-4" onSubmit={handleSubmit} noValidate>
        <div>
          <label htmlFor="name" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
            Category name
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
          <label htmlFor="sort_order" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
            Sort order
          </label>
          <input
            id="sort_order"
            name="sort_order"
            type="number"
            step="1"
            value={sortOrder}
            onChange={(event) => setSortOrder(event.target.value)}
            placeholder="0"
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
            disabled={createCategory.isPending}
            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {createCategory.isPending ? 'Creating…' : 'Create category'}
          </button>
          <Link
            to={`/merchant/stores/${storeId}/categories`}
            className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
          >
            Cancel
          </Link>
        </div>
      </form>
    </div>
  )
}
