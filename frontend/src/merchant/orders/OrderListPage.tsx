import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import type { OrderStatus } from '../../services/ordersApi'
import { useOrdersList } from './useOrders'

const STATUS_LABELS: Record<OrderStatus, string> = {
  pending: 'Pending',
  paid: 'Paid',
  processing: 'Processing',
  shipped: 'Shipped',
  completed: 'Completed',
  cancelled: 'Cancelled',
  refunded: 'Refunded',
}

const FILTERABLE_STATUSES: OrderStatus[] = [
  'pending',
  'paid',
  'processing',
  'shipped',
  'completed',
  'cancelled',
  'refunded',
]

export function OrderListPage() {
  const params = useParams<{ storeId: string }>()
  const storeId = Number(params.storeId)

  const [status, setStatus] = useState<OrderStatus | ''>('')
  const [page, setPage] = useState(1)

  const { data, isLoading, isError, error } = useOrdersList(storeId, {
    status: status || undefined,
    page,
  })

  function handleStatusChange(value: string) {
    setStatus(value as OrderStatus | '')
    setPage(1)
  }

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
          <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Orders</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Orders placed in this store.</p>
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

      {isLoading && <p className="text-sm text-slate-500 dark:text-slate-400">Loading orders…</p>}

      {isError && (
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {error instanceof ApiError ? error.message : 'Unable to load orders.'}
        </p>
      )}

      {data && data.data.length === 0 && (
        <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
          No orders yet.
        </div>
      )}

      {data && data.data.length > 0 && (
        <ul className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {data.data.map((order) => (
            <li key={order.id}>
              <Link
                to={`/merchant/stores/${storeId}/orders/${order.id}`}
                className="flex items-center justify-between px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800"
              >
                <div>
                  <p className="text-sm font-medium text-slate-900 dark:text-slate-100">{order.order_number}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    {order.customer_name} · ${order.total}
                  </p>
                </div>
                <span className="text-xs text-slate-500 dark:text-slate-400">
                  {STATUS_LABELS[order.status] ?? order.status}
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
