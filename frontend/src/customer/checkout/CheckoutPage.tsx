import { Elements } from '@stripe/react-stripe-js'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { stripePromise } from '../../services/stripeClient'
import { useCart } from '../cart/CartContext'
import { CheckoutForm } from './CheckoutForm'

export function CheckoutPage() {
  const params = useParams<{ storeId: string }>()
  const storeId = params.storeId
  const { items } = useCart()

  // Once checkout succeeds, CheckoutForm clears the cart — which would
  // otherwise make `items.length === 0` immediately below fall through to
  // the generic "cart is empty" state instead of CheckoutForm's own success
  // message. This flag distinguishes "empty because nothing was ever
  // added" (show the empty state) from "empty because checkout just
  // succeeded" (keep rendering the form, which renders its success state).
  const [hasSucceeded, setHasSucceeded] = useState(false)

  if (items.length === 0 && !hasSucceeded) {
    return (
      <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
        Your cart is empty.{' '}
        <Link to={`/store/${storeId}/products`} className="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
          Browse products
        </Link>
      </div>
    )
  }

  if (!stripePromise) {
    return (
      <p role="alert" className="text-sm text-red-600 dark:text-red-400">
        Payments are not configured in this environment.
      </p>
    )
  }

  return (
    <div className="space-y-6">
      <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Checkout</h1>
      <Elements stripe={stripePromise}>
        <CheckoutForm onSucceeded={() => setHasSucceeded(true)} />
      </Elements>
    </div>
  )
}
