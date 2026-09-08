import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import type { OrganizationStatus } from '../../services/organizationsApi'
import { useOrganizationsList } from './useOrganizations'

const STATUS_LABELS: Record<OrganizationStatus, string> = {
  pending: 'Pending',
  active: 'Active',
  rejected: 'Rejected',
  suspended: 'Suspended',
}

const FILTERABLE_STATUSES: OrganizationStatus[] = ['pending', 'active', 'rejected', 'suspended']

export function OrganizationListPage() {
  const [status, setStatus] = useState<OrganizationStatus | ''>('')
  const [page, setPage] = useState(1)

  const { data, isLoading, isError, error } = useOrganizationsList({
    status: status || undefined,
    page,
  })

  function handleStatusChange(value: string) {
    setStatus(value as OrganizationStatus | '')
    setPage(1)
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Organizations</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Merchant organizations registered on the platform.
          </p>
        </div>

        <select
          value={status}
          onChange={(event) => handleStatusChange(event.target.value)}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
        >
          <option value="">All statuses</option>
          {FILTERABLE_STATUSES.map((value) => (
            <option key={value} value={value}>
              {STATUS_LABELS[value]}
            </option>
          ))}
        </select>
      </div>

      {isLoading && <p className="text-sm text-slate-500 dark:text-slate-400">Loading organizations…</p>}

      {isError && (
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {error instanceof ApiError ? error.message : 'Unable to load organizations.'}
        </p>
      )}

      {data && data.data.length === 0 && (
        <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
          No organizations yet.
        </div>
      )}

      {data && data.data.length > 0 && (
        <ul className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {data.data.map((organization) => (
            <li key={organization.id}>
              <Link
                to={`/admin/organizations/${organization.id}`}
                className="flex items-center justify-between px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800"
              >
                <div>
                  <p className="text-sm font-medium text-slate-900 dark:text-slate-100">{organization.name}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">{organization.slug}</p>
                </div>
                <span className="text-xs text-slate-500 dark:text-slate-400">
                  {STATUS_LABELS[organization.status] ?? organization.status}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      {data && data.meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-slate-500 dark:text-slate-400">
          <button
            type="button"
            onClick={() => setPage((current) => Math.max(1, current - 1))}
            disabled={data.meta.current_page <= 1}
            className="rounded-md border border-slate-300 px-3 py-1.5 font-medium transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
          >
            Previous
          </button>
          <span>
            Page {data.meta.current_page} of {data.meta.last_page}
          </span>
          <button
            type="button"
            onClick={() => setPage((current) => Math.min(data.meta.last_page, current + 1))}
            disabled={data.meta.current_page >= data.meta.last_page}
            className="rounded-md border border-slate-300 px-3 py-1.5 font-medium transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
          >
            Next
          </button>
        </div>
      )}
    </div>
  )
}
