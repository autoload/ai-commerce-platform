import { Fragment, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import type { AnalyticsRangePreset } from '../../services/analyticsApi'
import { useCustomerAnalytics, useOrderStatusBreakdown, useProductAnalytics, useSalesSummary } from './useAnalytics'

const RANGE_LABELS: Record<AnalyticsRangePreset, string> = {
  today: 'Today',
  last_7_days: 'Last 7 Days',
  last_30_days: 'Last 30 Days',
  this_month: 'This Month',
  last_month: 'Last Month',
}

const RANGE_PRESETS: AnalyticsRangePreset[] = ['today', 'last_7_days', 'last_30_days', 'this_month', 'last_month']

const STATUS_LABELS: Record<string, string> = {
  pending: 'Pending',
  paid: 'Paid',
  processing: 'Processing',
  shipped: 'Shipped',
  completed: 'Completed',
  cancelled: 'Cancelled',
  refunded: 'Refunded',
}

function StatTile({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
      <p className="text-xs font-medium text-slate-500 dark:text-slate-400">{label}</p>
      <p className="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-50">{value}</p>
    </div>
  )
}

function formatGrowth(growthPercent: number | null): string {
  if (growthPercent === null) return 'N/A'
  const sign = growthPercent > 0 ? '+' : ''
  return `${sign}${growthPercent.toFixed(2)}%`
}

export function AnalyticsPage() {
  const params = useParams<{ storeId: string }>()
  const storeId = Number(params.storeId)
  const [range, setRange] = useState<AnalyticsRangePreset>('last_30_days')
  const [expandedProductId, setExpandedProductId] = useState<number | null>(null)

  const sales = useSalesSummary(storeId, range)
  const orders = useOrderStatusBreakdown(storeId, range)
  const products = useProductAnalytics(storeId, range)
  const customers = useCustomerAnalytics(storeId, range)

  function errorMessage(error: unknown, fallback: string): string {
    if (error instanceof ApiError) {
      return error.status === 403 ? 'You do not have access to Analytics for this store.' : error.message
    }
    return fallback
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
          <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Analytics</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Sales, orders, products, and customers for this store.</p>
        </div>

        <select
          value={range}
          onChange={(event) => setRange(event.target.value as AnalyticsRangePreset)}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
        >
          {RANGE_PRESETS.map((preset) => (
            <option key={preset} value={preset}>
              {RANGE_LABELS[preset]}
            </option>
          ))}
        </select>
      </div>

      {/* Sales Summary */}
      <section className="space-y-3">
        <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300">Sales</h2>

        {sales.isLoading && <p className="text-sm text-slate-500 dark:text-slate-400">Loading sales summary…</p>}
        {sales.isError && (
          <p role="alert" className="text-sm text-red-600 dark:text-red-400">
            {errorMessage(sales.error, 'Unable to load sales summary.')}
          </p>
        )}

        {sales.data && (
          <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <StatTile label="Gross Sales" value={`$${sales.data.data.gross_sales}`} />
            <StatTile label="Sales Refunds" value={`$${sales.data.data.sales_refunds}`} />
            <StatTile label="Net Sales" value={`$${sales.data.data.net_sales}`} />
            <StatTile label="Orders" value={String(sales.data.data.order_count)} />
            <StatTile label="AOV" value={sales.data.data.aov !== null ? `$${sales.data.data.aov}` : 'N/A'} />
            <StatTile label="Sales Growth" value={formatGrowth(sales.data.data.growth_percent)} />
          </div>
        )}
      </section>

      {/* Order Analytics */}
      <section className="space-y-3">
        <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300">Orders</h2>
        <p className="text-xs text-slate-500 dark:text-slate-400">Current status of orders created during the selected period.</p>

        {orders.isLoading && <p className="text-sm text-slate-500 dark:text-slate-400">Loading order breakdown…</p>}
        {orders.isError && (
          <p role="alert" className="text-sm text-red-600 dark:text-red-400">
            {errorMessage(orders.error, 'Unable to load order breakdown.')}
          </p>
        )}

        {orders.data && (
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            {Object.entries(orders.data.data.counts).map(([status, count]) => (
              <StatTile key={status} label={STATUS_LABELS[status] ?? status} value={String(count)} />
            ))}
          </div>
        )}
      </section>

      {/* Product Analytics */}
      <section className="space-y-3">
        <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300">Products</h2>

        {products.isLoading && <p className="text-sm text-slate-500 dark:text-slate-400">Loading product analytics…</p>}
        {products.isError && (
          <p role="alert" className="text-sm text-red-600 dark:text-red-400">
            {errorMessage(products.error, 'Unable to load product analytics.')}
          </p>
        )}

        {products.data && products.data.data.products.length === 0 && (
          <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
            No product sales in this period.
          </div>
        )}

        {products.data && products.data.data.products.length > 0 && (
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-200 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                <tr>
                  <th className="px-4 py-2 font-medium">Product</th>
                  <th className="px-4 py-2 font-medium">Revenue</th>
                  <th className="px-4 py-2 font-medium">Qty Sold</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                {products.data.data.products.map((product) => {
                  const rowKey = product.product_id ?? -1
                  const isExpanded = expandedProductId === rowKey

                  return (
                    <Fragment key={rowKey}>
                      <tr
                        className="cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800"
                        onClick={() => setExpandedProductId(isExpanded ? null : rowKey)}
                      >
                        <td className="px-4 py-2 text-slate-900 dark:text-slate-100">{product.product_name}</td>
                        <td className="px-4 py-2 text-slate-700 dark:text-slate-300">${product.revenue}</td>
                        <td className="px-4 py-2 text-slate-700 dark:text-slate-300">{product.quantity_sold}</td>
                      </tr>
                      {isExpanded && (
                        <tr>
                          <td colSpan={3} className="bg-slate-50 px-4 py-2 dark:bg-slate-800/50">
                            {product.daily_trend.length === 0 ? (
                              <p className="text-xs text-slate-500 dark:text-slate-400">No daily trend data.</p>
                            ) : (
                              <table className="w-full text-xs">
                                <thead className="text-slate-500 dark:text-slate-400">
                                  <tr>
                                    <th className="py-1 text-left font-medium">Date</th>
                                    <th className="py-1 text-left font-medium">Revenue</th>
                                    <th className="py-1 text-left font-medium">Qty</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {product.daily_trend.map((point) => (
                                    <tr key={point.date}>
                                      <td className="py-1 text-slate-700 dark:text-slate-300">{point.date}</td>
                                      <td className="py-1 text-slate-700 dark:text-slate-300">${point.revenue}</td>
                                      <td className="py-1 text-slate-700 dark:text-slate-300">{point.quantity_sold}</td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            )}
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {/* Customer Analytics */}
      <section className="space-y-3">
        <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300">Customers</h2>

        {customers.isLoading && <p className="text-sm text-slate-500 dark:text-slate-400">Loading customer analytics…</p>}
        {customers.isError && (
          <p role="alert" className="text-sm text-red-600 dark:text-red-400">
            {errorMessage(customers.error, 'Unable to load customer analytics.')}
          </p>
        )}

        {customers.data && (
          <>
            <div className="grid gap-3 sm:grid-cols-2">
              <StatTile label="New Customers" value={String(customers.data.data.new_customers)} />
              <StatTile label="Returning Customers" value={String(customers.data.data.returning_customers)} />
            </div>

            {customers.data.data.top_customers.length === 0 ? (
              <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                No customer spending in this period.
              </div>
            ) : (
              <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <table className="w-full text-left text-sm">
                  <thead className="border-b border-slate-200 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <tr>
                      <th className="px-4 py-2 font-medium">Customer</th>
                      <th className="px-4 py-2 font-medium">Net Sales</th>
                      <th className="px-4 py-2 font-medium">Orders</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                    {customers.data.data.top_customers.map((customer) => (
                      <tr key={customer.customer_id}>
                        <td className="px-4 py-2 text-slate-900 dark:text-slate-100">
                          {customer.name}
                          <span className="ml-2 text-xs text-slate-500 dark:text-slate-400">{customer.email}</span>
                        </td>
                        <td className="px-4 py-2 text-slate-700 dark:text-slate-300">${customer.net_sales}</td>
                        <td className="px-4 py-2 text-slate-700 dark:text-slate-300">{customer.order_count}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}
      </section>
    </div>
  )
}
