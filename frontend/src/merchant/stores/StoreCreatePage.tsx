import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import { useMerchantAuth } from '../auth/MerchantAuthContext'
import { useCreateStore } from './useStores'

export function StoreCreatePage() {
  const { role, organization } = useMerchantAuth()
  const navigate = useNavigate()
  const createStore = useCreateStore()
  const [name, setName] = useState('')
  const [validationError, setValidationError] = useState<string | null>(null)

  if (role !== 'owner') {
    return (
      <div className="space-y-4">
        <p className="text-sm text-slate-600 dark:text-slate-300">Only organization owners can create stores.</p>
        <Link to="/merchant/stores" className="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
          ← Back to stores
        </Link>
      </div>
    )
  }

  if (organization && organization.status !== 'active') {
    return (
      <div className="space-y-4">
        <p className="text-sm text-slate-600 dark:text-slate-300">
          Your organization must be approved before you can create stores. Current status:{' '}
          <span className="font-medium">{organization.status}</span>.
        </p>
        <Link to="/merchant/stores" className="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
          ← Back to stores
        </Link>
      </div>
    )
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setValidationError(null)

    if (!name) {
      setValidationError('Store name is required.')
      return
    }

    try {
      const { data: store } = await createStore.mutateAsync(name)
      navigate(`/merchant/stores/${store.id}`, { replace: true })
    } catch {
      // Server-side failure message is already surfaced via mutationError below.
    }
  }

  const mutationError =
    createStore.error instanceof ApiError ? createStore.error.message : null
  const errorMessage = validationError ?? mutationError

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Create store</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          A URL-friendly slug will be generated from the name automatically.
        </p>
      </div>

      <form className="max-w-sm space-y-4" onSubmit={handleSubmit} noValidate>
        <div>
          <label htmlFor="name" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
            Store name
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

        {errorMessage && (
          <p role="alert" className="text-sm text-red-600 dark:text-red-400">
            {errorMessage}
          </p>
        )}

        <div className="flex items-center gap-3">
          <button
            type="submit"
            disabled={createStore.isPending}
            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {createStore.isPending ? 'Creating…' : 'Create store'}
          </button>
          <Link to="/merchant/stores" className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">
            Cancel
          </Link>
        </div>
      </form>
    </div>
  )
}
