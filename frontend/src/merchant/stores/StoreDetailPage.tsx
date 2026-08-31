import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import { useMerchantAuth } from '../auth/MerchantAuthContext'
import { useDeleteStore, useStore, useUpdateStore } from './useStores'

export function StoreDetailPage() {
  const params = useParams<{ storeId: string }>()
  const storeId = Number(params.storeId)
  const { role } = useMerchantAuth()
  const navigate = useNavigate()

  const { data, isLoading, isError, error } = useStore(storeId)
  const updateStore = useUpdateStore(storeId)
  const deleteStore = useDeleteStore()

  const [isEditing, setIsEditing] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const canManage = role === 'owner'

  if (isLoading) {
    return <p className="text-sm text-slate-500 dark:text-slate-400">Loading store…</p>
  }

  if (isError || !data) {
    const status = error instanceof ApiError ? error.status : null
    const message =
      status === 404
        ? 'Store not found.'
        : status === 403
          ? 'You do not have access to this store.'
          : error instanceof ApiError
            ? error.message
            : 'Unable to load this store.'

    return (
      <div className="space-y-4">
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {message}
        </p>
        <Link to="/merchant/stores" className="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
          ← Back to stores
        </Link>
      </div>
    )
  }

  const store = data.data

  async function handleUpdate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const name = String(formData.get('name') ?? '').trim()
    if (!name) {
      return
    }

    try {
      await updateStore.mutateAsync({ name })
      setIsEditing(false)
    } catch {
      // Server-side failure message is already surfaced via updateStore.error below.
    }
  }

  async function handleToggleStatus() {
    try {
      await updateStore.mutateAsync({ status: store.status === 'active' ? 'inactive' : 'active' })
    } catch {
      // Surfaced via updateStore.error below.
    }
  }

  async function handleDelete() {
    try {
      await deleteStore.mutateAsync(store.id)
      navigate('/merchant/stores', { replace: true })
    } catch {
      // Surfaced via deleteStore.error below.
    }
  }

  const updateErrorMessage = updateStore.error instanceof ApiError ? updateStore.error.message : null
  const deleteErrorMessage = deleteStore.error instanceof ApiError ? deleteStore.error.message : null

  return (
    <div className="space-y-6">
      <Link
        to="/merchant/stores"
        className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
      >
        ← Back to stores
      </Link>

      <div className="grid gap-3 sm:grid-cols-2">
        <Link
          to={`/merchant/stores/${store.id}/products`}
          className="block rounded-lg border border-slate-200 bg-white p-4 text-sm font-medium text-slate-900 shadow-sm transition hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
        >
          Products →
        </Link>
        <Link
          to={`/merchant/stores/${store.id}/orders`}
          className="block rounded-lg border border-slate-200 bg-white p-4 text-sm font-medium text-slate-900 shadow-sm transition hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
        >
          Orders →
        </Link>
      </div>

      <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        {!isEditing ? (
          <div className="flex items-start justify-between">
            <div>
              <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">{store.name}</h1>
              <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{store.slug}</p>
            </div>
            <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
              {store.status === 'active' ? 'Active' : 'Inactive'}
            </span>
          </div>
        ) : (
          <form className="space-y-3" onSubmit={handleUpdate}>
            <div>
              <label htmlFor="name" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                Store name
              </label>
              <input
                id="name"
                name="name"
                type="text"
                defaultValue={store.name}
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              />
            </div>
            <div className="flex items-center gap-3">
              <button
                type="submit"
                disabled={updateStore.isPending}
                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {updateStore.isPending ? 'Saving…' : 'Save'}
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
              Edit name
            </button>
            <button
              type="button"
              onClick={handleToggleStatus}
              disabled={updateStore.isPending}
              className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              {store.status === 'active' ? 'Deactivate' : 'Activate'}
            </button>

            {!confirmingDelete ? (
              <button
                type="button"
                onClick={() => setConfirmingDelete(true)}
                className="ml-auto rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950"
              >
                Delete store
              </button>
            ) : (
              <div className="ml-auto flex items-center gap-2">
                <span className="text-sm text-slate-600 dark:text-slate-300">Delete this store?</span>
                <button
                  type="button"
                  onClick={handleDelete}
                  disabled={deleteStore.isPending}
                  className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {deleteStore.isPending ? 'Deleting…' : 'Confirm'}
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
