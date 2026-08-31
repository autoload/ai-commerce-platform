import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import type { MerchantOrderStatus, OrderStatus } from '../../services/ordersApi'
import { useMerchantAuth } from '../auth/MerchantAuthContext'
import { useOrder, useUpdateOrderStatus } from './useOrders'

const STATUS_LABELS: Record<OrderStatus, string> = {
  pending: 'Pending',
  paid: 'Paid',
  processing: 'Processing',
  shipped: 'Shipped',
  completed: 'Completed',
  cancelled: 'Cancelled',
  refunded: 'Refunded',
}

/**
 * Client-side mirror of the backend's MerchantOrderStatusTransitions
 * whitelist — presentation only, so the right button(s) render. The
 * backend's own OrderPolicy + MerchantOrderStatusTransitions remain fully
 * authoritative regardless of what's shown here.
 */
const NEXT_ACTIONS: Partial<Record<OrderStatus, { status: MerchantOrderStatus; label: string }>> = {
  pending: { status: 'cancelled', label: 'Cancel order' },
  paid: { status: 'processing', label: 'Begin processing' },
  processing: { status: 'shipped', label: 'Mark shipped' },
  shipped: { status: 'completed', label: 'Mark completed' },
}

export function OrderDetailPage() {
  const params = useParams<{ storeId: string; orderId: string }>()
  const storeId = Number(params.storeId)
  const orderId = Number(params.orderId)
  const { role } = useMerchantAuth()

  const { data, isLoading, isError, error } = useOrder(storeId, orderId)
  const updateStatus = useUpdateOrderStatus(storeId, orderId)

  const canManage = role === 'owner' || role === 'store_admin'

  if (isLoading) {
    return <p className="text-sm text-slate-500 dark:text-slate-400">Loading order…</p>
  }

  if (isError || !data) {
    const status = error instanceof ApiError ? error.status : null
    const message =
      status === 404
        ? 'Order not found.'
        : status === 403
          ? 'You do not have access to this order.'
          : error instanceof ApiError
            ? error.message
            : 'Unable to load this order.'

    return (
      <div className="space-y-4">
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {message}
        </p>
        <Link
          to={`/merchant/stores/${storeId}/orders`}
          className="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
        >
          ← Back to orders
        </Link>
      </div>
    )
  }

  const order = data.data
  const nextAction = NEXT_ACTIONS[order.status]
  const updateErrorMessage = updateStatus.error instanceof ApiError ? updateStatus.error.message : null

  async function handleTransition(target: MerchantOrderStatus) {
    try {
      await updateStatus.mutateAsync(target)
    } catch {
      // Surfaced via updateStatus.error below.
    }
  }

  return (
    <div className="space-y-6">
      <Link
        to={`/merchant/stores/${storeId}/orders`}
        className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
      >
        ← Back to orders
      </Link>

      <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-start justify-between">
          <div>
            <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">{order.order_number}</h1>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
              {order.customer_name} · {order.customer_email}
            </p>
          </div>
          <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
            {STATUS_LABELS[order.status] ?? order.status}
          </span>
        </div>

        <dl className="mt-4 space-y-1 text-sm">
          <div className="flex gap-2">
            <dt className="text-slate-500 dark:text-slate-400">Total</dt>
            <dd className="text-slate-900 dark:text-slate-100">${order.total}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="text-slate-500 dark:text-slate-400">Placed</dt>
            <dd className="text-slate-900 dark:text-slate-100">
              {order.created_at ? new Date(order.created_at).toLocaleString() : '—'}
            </dd>
          </div>
        </dl>

        {canManage && nextAction && (
          <div className="mt-6 flex items-center gap-3 border-t border-slate-200 pt-4 dark:border-slate-800">
            <button
              type="button"
              onClick={() => handleTransition(nextAction.status)}
              disabled={updateStatus.isPending}
              className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {updateStatus.isPending ? 'Saving…' : nextAction.label}
            </button>
          </div>
        )}

        {updateErrorMessage && (
          <p role="alert" className="mt-3 text-sm text-red-600 dark:text-red-400">
            {updateErrorMessage}
          </p>
        )}
      </div>

      {order.items && order.items.length > 0 && (
        <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-50">Items</h2>
          <ul className="mt-3 divide-y divide-slate-200 dark:divide-slate-800">
            {order.items.map((item) => (
              <li key={item.id} className="flex items-center justify-between py-2 text-sm">
                <div>
                  <p className="text-slate-900 dark:text-slate-100">{item.product_name}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    {item.sku} · qty {item.quantity}
                  </p>
                </div>
                <span className="text-slate-900 dark:text-slate-100">${item.line_total}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {order.shipping_address && (
        <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-50">Shipping address</h2>
          <address className="mt-3 text-sm not-italic text-slate-700 dark:text-slate-300">
            {order.shipping_address.recipient_name}
            <br />
            {order.shipping_address.line1}
            {order.shipping_address.line2 && (
              <>
                <br />
                {order.shipping_address.line2}
              </>
            )}
            <br />
            {order.shipping_address.city}, {order.shipping_address.state} {order.shipping_address.postal_code}
            <br />
            {order.shipping_address.country}
          </address>
        </div>
      )}
    </div>
  )
}
