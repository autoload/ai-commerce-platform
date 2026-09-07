import { Link, useLocation, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import type { OrderStatus, PaymentStatus } from '../../services/customerOrdersApi'
import { useCustomerOrder } from './useCustomerOrders'

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

// The {orderId} route param is used only to know WHICH order to ask the
// server for — it is never treated as proof of ownership. Authorization is
// entirely server-side (Customer\OrderController::resolveOrder(), scoped
// to the authenticated CustomerContext); a foreign/nonexistent/cross-store
// id all resolve to the same 404 here, rendered below exactly like any
// other error, not specially trusted or bypassed.
export function OrderDetailPage() {
  const params = useParams<{ storeId: string; orderId: string }>()
  const storeId = Number(params.storeId)
  const orderId = Number(params.orderId)
  const location = useLocation()

  // Router state is display-only (a one-time "just placed" banner) — never
  // a substitute for the order data itself, which always comes from the
  // authenticated API fetch below.
  const justPlaced = (location.state as { justPlaced?: boolean } | null)?.justPlaced === true

  const { data, isLoading, isError, error } = useCustomerOrder(storeId, orderId)

  if (isLoading) {
    return <p className="text-sm text-slate-500 dark:text-slate-400">Loading order…</p>
  }

  if (isError || !data) {
    const status = error instanceof ApiError ? error.status : null
    const message =
      status === 404
        ? 'Order not found.'
        : error instanceof ApiError
          ? error.message
          : 'Unable to load this order.'

    return (
      <div className="space-y-4">
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {message}
        </p>
        <Link
          to={`/store/${storeId}/orders`}
          className="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
        >
          ← Back to your orders
        </Link>
      </div>
    )
  }

  const order = data.data

  return (
    <div className="space-y-6">
      <Link
        to={`/store/${storeId}/orders`}
        className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
      >
        ← Back to your orders
      </Link>

      {justPlaced && (
        <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">
          Your order has been placed. {order.payment_status !== 'succeeded' && "We're still confirming your payment — this page always shows its current status."}
        </div>
      )}

      <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-start justify-between">
          <div>
            <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">{order.order_number}</h1>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
              Placed {order.created_at ? new Date(order.created_at).toLocaleString() : '—'}
            </p>
          </div>
          <div className="flex flex-col items-end gap-1">
            <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
              {STATUS_LABELS[order.status] ?? order.status}
            </span>
            {order.payment_status && (
              <span className="text-xs text-slate-500 dark:text-slate-400">
                {PAYMENT_STATUS_LABELS[order.payment_status] ?? order.payment_status}
              </span>
            )}
          </div>
        </div>

        <dl className="mt-4 space-y-1 text-sm">
          <div className="flex gap-2">
            <dt className="text-slate-500 dark:text-slate-400">Subtotal</dt>
            <dd className="text-slate-900 dark:text-slate-100">
              ${order.subtotal} {order.currency.toUpperCase()}
            </dd>
          </div>
          {Number(order.discount_total) > 0 && (
            <div className="flex gap-2">
              <dt className="text-slate-500 dark:text-slate-400">Discount</dt>
              <dd className="text-slate-900 dark:text-slate-100">-${order.discount_total}</dd>
            </div>
          )}
          {Number(order.tax_total) > 0 && (
            <div className="flex gap-2">
              <dt className="text-slate-500 dark:text-slate-400">Tax</dt>
              <dd className="text-slate-900 dark:text-slate-100">${order.tax_total}</dd>
            </div>
          )}
          <div className="flex gap-2 font-medium">
            <dt className="text-slate-500 dark:text-slate-400">Total</dt>
            <dd className="text-slate-900 dark:text-slate-100">
              ${order.total} {order.currency.toUpperCase()}
            </dd>
          </div>
          {order.paid_at && (
            <div className="flex gap-2">
              <dt className="text-slate-500 dark:text-slate-400">Paid</dt>
              <dd className="text-slate-900 dark:text-slate-100">{new Date(order.paid_at).toLocaleString()}</dd>
            </div>
          )}
          {order.cancelled_at && (
            <div className="flex gap-2">
              <dt className="text-slate-500 dark:text-slate-400">Cancelled</dt>
              <dd className="text-slate-900 dark:text-slate-100">{new Date(order.cancelled_at).toLocaleString()}</dd>
            </div>
          )}
        </dl>
      </div>

      {order.items && order.items.length > 0 && (
        <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-50">Items</h2>
          <ul className="mt-3 divide-y divide-slate-200 dark:divide-slate-800">
            {order.items.map((item, index) => (
              <li key={`${item.product_variant_id ?? 'item'}-${index}`} className="flex items-center justify-between py-2 text-sm">
                <div>
                  <p className="text-slate-900 dark:text-slate-100">{item.product_name}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    {item.sku} · qty {item.quantity} · ${item.unit_price} each
                  </p>
                  {item.selected_options && Object.keys(item.selected_options).length > 0 && (
                    <p className="text-xs text-slate-500 dark:text-slate-400">
                      {Object.entries(item.selected_options)
                        .map(([option, value]) => `${option}: ${value}`)
                        .join(', ')}
                    </p>
                  )}
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
