import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import { useMerchantAuth } from '../auth/MerchantAuthContext'
import { useCategory, useDeleteCategory, useUpdateCategory } from './useCategories'

export function CategoryDetailPage() {
  const params = useParams<{ storeId: string; categoryId: string }>()
  const storeId = Number(params.storeId)
  const categoryId = Number(params.categoryId)
  const { role } = useMerchantAuth()
  const navigate = useNavigate()

  const { data, isLoading, isError, error } = useCategory(storeId, categoryId)
  const updateCategory = useUpdateCategory(storeId, categoryId)
  const deleteCategory = useDeleteCategory(storeId)

  const [isEditing, setIsEditing] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const canManage = role === 'owner' || role === 'store_admin'

  if (isLoading) {
    return <p className="text-sm text-slate-500 dark:text-slate-400">Loading category…</p>
  }

  if (isError || !data) {
    const status = error instanceof ApiError ? error.status : null
    const message =
      status === 404
        ? 'Category not found.'
        : status === 403
          ? 'You do not have access to this category.'
          : error instanceof ApiError
            ? error.message
            : 'Unable to load this category.'

    return (
      <div className="space-y-4">
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {message}
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

  const category = data.data

  async function handleUpdate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const name = String(formData.get('name') ?? '').trim()
    const description = String(formData.get('description') ?? '').trim()
    const sortOrderRaw = String(formData.get('sort_order') ?? '').trim()

    if (!name) {
      return
    }
    let sortOrder: number | undefined
    if (sortOrderRaw !== '') {
      sortOrder = Number(sortOrderRaw)
      if (!Number.isInteger(sortOrder)) {
        return
      }
    }

    try {
      await updateCategory.mutateAsync({ name, description, sort_order: sortOrder })
      setIsEditing(false)
    } catch {
      // Server-side failure message is already surfaced via updateErrorMessage below.
    }
  }

  async function handleDelete() {
    try {
      await deleteCategory.mutateAsync(category.id)
      navigate(`/merchant/stores/${storeId}/categories`, { replace: true })
    } catch {
      // Surfaced via deleteErrorMessage below — includes the backend's
      // "has one or more products still assigned to it" 422 message when
      // deletion is blocked by a referencing product.
    }
  }

  const updateErrorMessage = updateCategory.error instanceof ApiError ? updateCategory.error.message : null
  const deleteErrorMessage = deleteCategory.error instanceof ApiError ? deleteCategory.error.message : null

  return (
    <div className="space-y-6">
      <Link
        to={`/merchant/stores/${storeId}/categories`}
        className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
      >
        ← Back to categories
      </Link>

      <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        {!isEditing ? (
          <div className="flex items-start justify-between">
            <div>
              <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">{category.name}</h1>
              <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{category.slug}</p>
              {category.description && (
                <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{category.description}</p>
              )}
              <dl className="mt-4 space-y-1 text-sm">
                <div className="flex gap-2">
                  <dt className="text-slate-500 dark:text-slate-400">Sort order</dt>
                  <dd className="text-slate-900 dark:text-slate-100">{category.sort_order}</dd>
                </div>
              </dl>
            </div>
          </div>
        ) : (
          <form className="space-y-3" onSubmit={handleUpdate}>
            <div>
              <label htmlFor="name" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                Category name
              </label>
              <input
                id="name"
                name="name"
                type="text"
                defaultValue={category.name}
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
                defaultValue={category.description ?? ''}
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
                defaultValue={category.sort_order}
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              />
            </div>
            <div className="flex items-center gap-3">
              <button
                type="submit"
                disabled={updateCategory.isPending}
                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {updateCategory.isPending ? 'Saving…' : 'Save'}
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

            {!confirmingDelete ? (
              <button
                type="button"
                onClick={() => setConfirmingDelete(true)}
                className="ml-auto rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950"
              >
                Delete category
              </button>
            ) : (
              <div className="ml-auto flex items-center gap-2">
                <span className="text-sm text-slate-600 dark:text-slate-300">Delete this category?</span>
                <button
                  type="button"
                  onClick={handleDelete}
                  disabled={deleteCategory.isPending}
                  className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {deleteCategory.isPending ? 'Deleting…' : 'Confirm'}
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
    </div>
  )
}
