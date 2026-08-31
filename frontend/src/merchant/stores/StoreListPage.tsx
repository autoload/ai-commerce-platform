import { Link } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import { useMerchantAuth } from '../auth/MerchantAuthContext'
import { useStoresList } from './useStores'

const STATUS_LABELS: Record<string, string> = {
  active: 'Active',
  inactive: 'Inactive',
}

export function StoreListPage() {
  const { role } = useMerchantAuth()
  const { data, isLoading, isError, error } = useStoresList()
  const canCreate = role === 'owner'

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Stores</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {role === 'owner' ? 'Every store in your organization.' : 'Stores you have access to.'}
          </p>
        </div>

        {canCreate && (
          <Link
            to="/merchant/stores/new"
            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500"
          >
            Create store
          </Link>
        )}
      </div>

      {isLoading && <p className="text-sm text-slate-500 dark:text-slate-400">Loading stores…</p>}

      {isError && (
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {error instanceof ApiError ? error.message : 'Unable to load stores.'}
        </p>
      )}

      {data && data.data.length === 0 && (
        <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
          {role === 'owner' ? 'No stores yet.' : 'You have not been assigned to any store yet.'}
        </div>
      )}

      {data && data.data.length > 0 && (
        <ul className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {data.data.map((store) => (
            <li key={store.id}>
              <Link
                to={`/merchant/stores/${store.id}`}
                className="flex items-center justify-between px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800"
              >
                <div>
                  <p className="text-sm font-medium text-slate-900 dark:text-slate-100">{store.name}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">{store.slug}</p>
                </div>
                <span className="text-xs text-slate-500 dark:text-slate-400">
                  {STATUS_LABELS[store.status] ?? store.status}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
