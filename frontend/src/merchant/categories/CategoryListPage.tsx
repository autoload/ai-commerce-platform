import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import { useMerchantAuth } from '../auth/MerchantAuthContext'
import { useCategoriesList } from './useCategories'

export function CategoryListPage() {
  const params = useParams<{ storeId: string }>()
  const storeId = Number(params.storeId)
  const { role } = useMerchantAuth()
  const { data, isLoading, isError, error } = useCategoriesList(storeId)
  const canCreate = role === 'owner' || role === 'store_admin'

  return (
    <div className="space-y-6">
      <Link
        to={`/merchant/stores/${storeId}`}
        className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
      >
        ← Back to store
      </Link>

      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Categories</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Categories in this store.</p>
        </div>

        {canCreate && (
          <Link
            to={`/merchant/stores/${storeId}/categories/new`}
            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500"
          >
            Create category
          </Link>
        )}
      </div>

      {isLoading && <p className="text-sm text-slate-500 dark:text-slate-400">Loading categories…</p>}

      {isError && (
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {error instanceof ApiError ? error.message : 'Unable to load categories.'}
        </p>
      )}

      {data && data.data.length === 0 && (
        <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
          No categories yet.
        </div>
      )}

      {data && data.data.length > 0 && (
        <ul className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {data.data.map((category) => (
            <li key={category.id}>
              <Link
                to={`/merchant/stores/${storeId}/categories/${category.id}`}
                className="flex items-center justify-between px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800"
              >
                <div>
                  <p className="text-sm font-medium text-slate-900 dark:text-slate-100">{category.name}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">{category.slug}</p>
                </div>
                <span className="text-xs text-slate-500 dark:text-slate-400">Order {category.sort_order}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
