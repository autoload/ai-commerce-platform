import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import { useCustomer } from './useCustomers'

export function CustomerDetailPage() {
  const params = useParams<{ storeId: string; customerId: string }>()
  const storeId = Number(params.storeId)
  const customerId = Number(params.customerId)

  const { data, isLoading, isError, error } = useCustomer(storeId, customerId)

  if (isLoading) {
    return <p className="text-sm text-slate-500 dark:text-slate-400">Loading customer…</p>
  }

  if (isError || !data) {
    const status = error instanceof ApiError ? error.status : null
    const message =
      status === 404
        ? 'Customer not found.'
        : status === 403
          ? 'You do not have access to this customer.'
          : error instanceof ApiError
            ? error.message
            : 'Unable to load this customer.'

    return (
      <div className="space-y-4">
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {message}
        </p>
        <Link
          to={`/merchant/stores/${storeId}/customers`}
          className="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
        >
          ← Back to customers
        </Link>
      </div>
    )
  }

  const customer = data.data

  return (
    <div className="space-y-6">
      <Link
        to={`/merchant/stores/${storeId}/customers`}
        className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
      >
        ← Back to customers
      </Link>

      <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-start justify-between">
          <div>
            <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">{customer.name}</h1>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{customer.email}</p>
          </div>
        </div>

        <dl className="mt-4 space-y-1 text-sm">
          <div className="flex gap-2">
            <dt className="text-slate-500 dark:text-slate-400">Phone</dt>
            <dd className="text-slate-900 dark:text-slate-100">{customer.phone ?? '—'}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="text-slate-500 dark:text-slate-400">Customer since</dt>
            <dd className="text-slate-900 dark:text-slate-100">
              {customer.created_at ? new Date(customer.created_at).toLocaleDateString() : '—'}
            </dd>
          </div>
          <div className="flex gap-2">
            <dt className="text-slate-500 dark:text-slate-400">Orders</dt>
            <dd className="text-slate-900 dark:text-slate-100">{customer.order_count}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="text-slate-500 dark:text-slate-400">Total spent</dt>
            <dd className="text-slate-900 dark:text-slate-100">${customer.total_spent}</dd>
          </div>
        </dl>
      </div>
    </div>
  )
}
