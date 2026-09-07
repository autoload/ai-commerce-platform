import { Link, useParams } from 'react-router-dom'
import { QuantityStepper } from '../../components/QuantityStepper'
import { useCart } from './CartContext'

export function CartPage() {
  const params = useParams<{ storeId: string }>()
  const storeId = params.storeId
  const { items, subtotal, isLoading, error, incrementItem, decrementItem, setItemQuantity, removeItem } = useCart()

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Your Cart</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Prices shown here are a snapshot from when each item was added — the final price and availability are
          always confirmed at checkout.
        </p>
      </div>

      {error && (
        <div role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-400">
          {error}
        </div>
      )}

      {isLoading ? (
        <p className="text-sm text-slate-500 dark:text-slate-400">Loading your cart…</p>
      ) : items.length === 0 ? (
        <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
          Your cart is empty.{' '}
          <Link to={`/store/${storeId}/products`} className="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
            Browse products
          </Link>
        </div>
      ) : (
        <>
          <ul className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
            {items.map((item) => {
              const lineTotal = Number(item.displayPrice) * item.quantity

              return (
                <li key={item.variantId} className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <Link
                      to={`/store/${storeId}/products/${item.productId}`}
                      className="text-sm font-medium text-slate-900 hover:text-indigo-600 dark:text-slate-100 dark:hover:text-indigo-400"
                    >
                      {item.productName}
                    </Link>
                    {item.variantLabel && (
                      <p className="text-xs text-slate-500 dark:text-slate-400">{item.variantLabel}</p>
                    )}
                    <p className="text-xs text-slate-500 dark:text-slate-400">SKU {item.sku}</p>
                    {!item.inStockAtAdd && (
                      <p className="mt-1 text-xs font-medium text-amber-600 dark:text-amber-400">
                        May no longer be available — availability is confirmed at checkout.
                      </p>
                    )}
                  </div>

                  <div className="flex items-center gap-6">
                    <QuantityStepper
                      value={item.quantity}
                      onIncrement={() => incrementItem(item.variantId)}
                      onDecrement={() => decrementItem(item.variantId)}
                      onChange={(next) => setItemQuantity(item.variantId, next)}
                      label={item.productName}
                    />
                    <span className="w-20 text-right text-sm font-medium text-slate-900 dark:text-slate-100">
                      ${lineTotal.toFixed(2)}
                    </span>
                    <button
                      type="button"
                      onClick={() => removeItem(item.variantId)}
                      className="text-sm font-medium text-red-600 hover:text-red-500 dark:text-red-400"
                    >
                      Remove
                    </button>
                  </div>
                </li>
              )
            })}
          </ul>

          <div className="flex items-center justify-between border-t border-slate-200 pt-4 dark:border-slate-800">
            <span className="text-sm font-medium text-slate-500 dark:text-slate-400">Subtotal</span>
            <span
              data-testid="cart-subtotal"
              className="text-lg font-semibold text-slate-900 dark:text-slate-50"
            >
              ${subtotal.toFixed(2)}
            </span>
          </div>

          <Link
            to={`/store/${storeId}/checkout`}
            className="block w-full rounded-md bg-indigo-600 px-3 py-2 text-center text-sm font-medium text-white transition hover:bg-indigo-500"
          >
            Checkout
          </Link>
        </>
      )}
    </div>
  )
}
