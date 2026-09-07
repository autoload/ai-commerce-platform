import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import type { OrderStatus, PaymentStatus } from '../../services/customerOrdersApi'
import { useCustomerOrdersList } from './useCustomerOrders'

const STATUS_LABELS: Record<OrderStatus, string> = {
  pending: 'Pending',
  paid: 'Paid',
  processing: 'Processing',
  shipped: 'Shipped',
  completed: 'Completed',
  cancelled: 'Cancelled',
  refunded: 'Refunded',
}

const PAYMENT_STATUS_LABELS: Record<PaymentStatus, string> = {
  requires_payment: 'Payment required',
  processing: 'Payment processing',
  succeeded: 'Paid',
  failed: 'Payment failed',
  canceled: 'Payment canceled',
}

// Server already sorts newest-first (Order::orderBy('created_at', 'desc')
// in Customer\OrderController::index()) — no client-side sort needed.
// Deliberately no status filter — Phase 7's design explicitly deferred it
// (not required by PRD §7.3), and this block doesn't add it either.
export function OrderHistoryPage() {
  const params = useParams<{ storeId: string }>()
  const storeId = Number(params.storeId)

  const [page, setPage] = useState(1)

  const { data, isLoading, isError, error } = useCustomerOrdersList(storeId, page)

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Your Orders</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Orders you've placed at this store.</p>
      </div>

      {isLoading && <p className="text-sm text-slate-500 dark:text-slate-400">Loading orders…</p>}

      {isError && (
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {error instanceof ApiError ? error.message : 'Unable to load your orders.'}
        </p>
      )}

      {data && data.data.length === 0 && (
        <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
          You haven't placed any orders yet.{' '}
          <Link
            to={`/store/${storeId}/products`}
            className="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
          >
            Browse products
          </Link>
        </div>
      )}

      {data && data.data.length > 0 && (
        <ul className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
          {data.data.map((order) => (
            <li key={order.id}>
              <Link
                to={`/store/${storeId}/orders/${order.id}`}
                className="flex items-center justify-between px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800"
              >
                <div>
                  <p className="text-sm font-medium text-slate-900 dark:text-slate-100">{order.order_number}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    {order.created_at ? new Date(order.created_at).toLocaleDateString() : '—'} · ${order.total}{' '}
                    {order.currency.toUpperCase()}
                  </p>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <span className="text-xs font-medium text-slate-700 dark:text-slate-300">
                    {STATUS_LABELS[order.status] ?? order.status}
                  </span>
                  {order.payment_status && (
                    <span className="text-xs text-slate-500 dark:text-slate-400">
                      {PAYMENT_STATUS_LABELS[order.payment_status] ?? order.payment_status}
                    </span>
                  )}
                </div>
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
