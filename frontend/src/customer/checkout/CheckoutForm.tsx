import { CardElement, useElements, useStripe } from '@stripe/react-stripe-js'
import { useRef, useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import type { CheckoutResponse } from '../../services/checkoutApi'
import { useCart } from '../cart/CartContext'
import { useCheckout } from './useCheckout'

type AddressFormState = {
  recipientName: string
  line1: string
  line2: string
  city: string
  state: string
  postalCode: string
  country: string
  phone: string
}

const EMPTY_ADDRESS: AddressFormState = {
  recipientName: '',
  line1: '',
  line2: '',
  city: '',
  state: '',
  postalCode: '',
  country: '',
  phone: '',
}

// idle -> submitting -> succeeded | paymentPending, or back to
// idle-with-an-error on failure. `orderError` (the /api/checkout request
// itself failed — cart changed, item unavailable, invalid input,
// duplicate-submission conflict) and `paymentError` (the checkout request
// succeeded but Stripe declined confirmation) are distinguished only for
// which recovery action makes sense to show — both otherwise render the
// same way. `paymentPending` (PaymentIntent status "processing") is
// deliberately NOT folded into `succeeded` — see the status check below.
type Status = 'idle' | 'submitting' | 'succeeded' | 'paymentPending' | 'orderError' | 'paymentError'

export function CheckoutForm({ onSucceeded }: { onSucceeded: () => void }) {
  const params = useParams<{ storeId: string }>()
  const storeId = params.storeId
  const stripe = useStripe()
  const elements = useElements()
  const { items, subtotal, clearCart } = useCart()
  const checkoutMutation = useCheckout()

  const [address, setAddress] = useState<AddressFormState>(EMPTY_ADDRESS)
  const [status, setStatus] = useState<Status>('idle')
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  // Generated once per page mount (a "checkout attempt") and reused across
  // every submit click during that attempt — including an accidental
  // double-click and a deliberate retry after a Stripe-level decline — as
  // long as the cart/address being submitted hasn't changed. This is what
  // makes the existing backend's required Idempotency-Key header actually
  // prevent a duplicate order: two requests with the same key and the same
  // cart/address always resolve to the same order (see
  // CheckoutOrderCreationService's idempotency-key-conflict handling). A
  // genuinely new attempt (the customer leaves and returns to /checkout)
  // gets a fresh key naturally, since the component remounts.
  //
  // If the submitted cart/address DOES change between two attempts under
  // this same mount (submittedSignatureRef below), a fresh key is forced —
  // see handleSubmit. The backend's own two-layer idempotency check
  // (Stripe's amount-match plus orders.idempotency_key_payload_hash) would
  // already reject a same-key-different-payload resubmission with a 409
  // rather than ever create a wrong order, so this is a UX correction, not
  // a correctness fix — without it, a customer who edits their cart mid-
  // checkout and retries would hit a confusing 409 instead of a clean new
  // attempt.
  const idempotencyKeyRef = useRef<string>(crypto.randomUUID())

  // What the current idempotencyKeyRef/checkoutResult pair was last built
  // from — compared against the cart/address at the start of every submit.
  const submittedSignatureRef = useRef<string | null>(null)

  // Set once the order/PaymentIntent has been created — retried Stripe
  // confirmations (after a card decline) reuse this instead of re-hitting
  // /api/checkout, since nothing about the order itself needs to change.
  // Invalidated in handleSubmit if the cart/address changed since it was set.
  const [checkoutResult, setCheckoutResult] = useState<CheckoutResponse | null>(null)

  const isSubmitting = status === 'submitting'
  const isTerminal = status === 'succeeded' || status === 'paymentPending'

  function updateAddress<K extends keyof AddressFormState>(key: K, value: string) {
    setAddress((current) => ({ ...current, [key]: value }))
  }

  function validateAddress(): string | null {
    if (!address.recipientName.trim() || !address.line1.trim() || !address.city.trim() || !address.state.trim()) {
      return 'Please fill in all required address fields.'
    }
    if (!/^[A-Za-z]{2}$/.test(address.country.trim())) {
      return 'Country must be a 2-letter code (e.g. US).'
    }
    if (!address.postalCode.trim()) {
      return 'Please enter a postal code.'
    }
    return null
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    // Belt-and-suspenders alongside the disabled button below — a
    // fast double-click could otherwise fire this handler twice before
    // React re-renders the disabled state.
    if (isSubmitting || isTerminal) {
      return
    }

    const validationError = validateAddress()
    if (validationError) {
      setStatus('orderError')
      setErrorMessage(validationError)
      return
    }

    const itemsPayload = items.map((item) => ({ product_variant_id: item.variantId, quantity: item.quantity }))
    const shippingAddress = {
      recipient_name: address.recipientName.trim(),
      line1: address.line1.trim(),
      line2: address.line2.trim() || undefined,
      city: address.city.trim(),
      state: address.state.trim(),
      postal_code: address.postalCode.trim(),
      country: address.country.trim().toUpperCase(),
      phone: address.phone.trim() || undefined,
    }

    // Same cart/address as the last attempt under this key -> reuse it
    // (scenario A: a double-click or a post-decline retry). Different ->
    // this is now a materially different checkout payload, so the old
    // key/order must not be reused (scenario B) — force a fresh key and
    // discard any previously-created order/PaymentIntent so the next
    // /api/checkout call below re-submits for real, under a key that has
    // never been used for any other payload.
    const currentSignature = JSON.stringify({ items: itemsPayload, shippingAddress })
    let effectiveResult = checkoutResult
    if (submittedSignatureRef.current !== null && submittedSignatureRef.current !== currentSignature) {
      idempotencyKeyRef.current = crypto.randomUUID()
      effectiveResult = null
      setCheckoutResult(null)
    }
    submittedSignatureRef.current = currentSignature

    setStatus('submitting')
    setErrorMessage(null)

    try {
      let result = effectiveResult

      if (!result) {
        result = await checkoutMutation.mutateAsync({
          idempotencyKey: idempotencyKeyRef.current,
          items: itemsPayload,
          shippingAddress,
        })
        setCheckoutResult(result)
      }

      if (!stripe || !elements) {
        throw new Error('Payment form is not ready yet. Please wait a moment and try again.')
      }
      const cardElement = elements.getElement(CardElement)
      if (!cardElement) {
        throw new Error('Payment form is not ready yet. Please wait a moment and try again.')
      }

      const confirmation = await stripe.confirmCardPayment(result.payment.client_secret, {
        payment_method: {
          card: cardElement,
          billing_details: { name: address.recipientName.trim() },
        },
      })

      if (confirmation.error) {
        setStatus('paymentError')
        setErrorMessage(confirmation.error.message ?? 'Your payment could not be confirmed. Please try again.')
        return
      }

      // A successful confirmCardPayment call means Stripe has resolved the
      // PaymentIntent client-side — it does NOT mean this app's own record
      // of the order is "paid" yet. That transition happens later, when
      // Stripe's webhook reaches this backend (see StripeWebhookTest.php /
      // the webhook controller) — asynchronous relative to this response.
      //
      // "succeeded" is the only status this app claims as a completed
      // payment: the backend's own PaymentStatus model treats "processing"
      // as a distinct, non-terminal state (PaymentStatus::Processing), and
      // only a later payment_intent.succeeded webhook ever marks the order
      // paid — this frontend must not get ahead of that by claiming
      // success, and must not clear the cart until the outcome is actually
      // known. Any other status (requires_payment_method, requires_action
      // left unresolved, etc.) is treated as not yet complete.
      const paymentIntent = confirmation.paymentIntent
      if (paymentIntent.status === 'succeeded') {
        clearCart()
        setStatus('succeeded')
        onSucceeded()
        return
      }

      if (paymentIntent.status === 'processing') {
        setStatus('paymentPending')
        return
      }

      setStatus('paymentError')
      setErrorMessage('Your payment could not be completed. Please try again.')
    } catch (error) {
      setStatus(checkoutResult ? 'paymentError' : 'orderError')
      setErrorMessage(error instanceof ApiError ? error.message : 'Something went wrong. Please try again.')
    }
  }

  if (status === 'succeeded') {
    return (
      <div role="status" className="space-y-4 rounded-lg border border-emerald-200 bg-emerald-50 p-6 dark:border-emerald-900 dark:bg-emerald-950">
        <h2 className="text-lg font-semibold text-emerald-800 dark:text-emerald-300">Payment successful</h2>
        <p className="text-sm text-emerald-700 dark:text-emerald-400">
          Your order {checkoutResult?.data.order_number} has been placed. A confirmation will be available in your
          order history shortly.
        </p>
        <Link
          to={`/store/${storeId}/products`}
          className="inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
        >
          Continue shopping
        </Link>
      </div>
    )
  }

  // PaymentIntent status "processing" — Stripe has not yet definitively
  // resolved this payment (see the status check above). The cart is
  // deliberately left intact and this is NOT presented as a success.
  if (status === 'paymentPending') {
    return (
      <div role="status" className="space-y-4 rounded-lg border border-amber-200 bg-amber-50 p-6 dark:border-amber-900 dark:bg-amber-950">
        <h2 className="text-lg font-semibold text-amber-800 dark:text-amber-300">Payment processing</h2>
        <p className="text-sm text-amber-700 dark:text-amber-400">
          Your order {checkoutResult?.data.order_number} has been placed and your payment is still being confirmed.
          This can take a moment — you'll see the final status once it's ready.
        </p>
        <Link
          to={`/store/${storeId}/products`}
          className="inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
        >
          Continue shopping
        </Link>
      </div>
    )
  }

  const authoritativeTotal = checkoutResult?.data.total

  return (
    <form onSubmit={handleSubmit} className="space-y-6" noValidate>
      <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Order summary</h2>
        <ul className="mt-3 space-y-2 text-sm">
          {items.map((item) => (
            <li key={item.variantId} className="flex justify-between text-slate-600 dark:text-slate-300">
              <span>
                {item.productName}
                {item.variantLabel ? ` (${item.variantLabel})` : ''} × {item.quantity}
              </span>
              <span>${(Number(item.displayPrice) * item.quantity).toFixed(2)}</span>
            </li>
          ))}
        </ul>
        <div className="mt-3 flex justify-between border-t border-slate-200 pt-3 text-sm font-medium dark:border-slate-800">
          <span className="text-slate-500 dark:text-slate-400">
            {authoritativeTotal ? 'Order total' : 'Estimated total'}
          </span>
          <span className="text-slate-900 dark:text-slate-100">${(authoritativeTotal ?? subtotal.toFixed(2))}</span>
        </div>
        {!authoritativeTotal && (
          <p className="mt-2 text-xs text-slate-400 dark:text-slate-500">
            This is a preview based on prices when items were added — the final total is confirmed by the server
            below.
          </p>
        )}
      </div>

      <fieldset disabled={isSubmitting} className="space-y-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <legend className="text-sm font-semibold text-slate-900 dark:text-slate-100">Shipping address</legend>

        <div>
          <label htmlFor="recipientName" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
            Full name
          </label>
          <input
            id="recipientName"
            value={address.recipientName}
            onChange={(event) => updateAddress('recipientName', event.target.value)}
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
          />
        </div>

        <div>
          <label htmlFor="line1" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
            Address line 1
          </label>
          <input
            id="line1"
            value={address.line1}
            onChange={(event) => updateAddress('line1', event.target.value)}
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
          />
        </div>

        <div>
          <label htmlFor="line2" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
            Address line 2 (optional)
          </label>
          <input
            id="line2"
            value={address.line2}
            onChange={(event) => updateAddress('line2', event.target.value)}
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label htmlFor="city" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
              City
            </label>
            <input
              id="city"
              value={address.city}
              onChange={(event) => updateAddress('city', event.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            />
          </div>
          <div>
            <label htmlFor="state" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
              State
            </label>
            <input
              id="state"
              value={address.state}
              onChange={(event) => updateAddress('state', event.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label htmlFor="postalCode" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
              Postal code
            </label>
            <input
              id="postalCode"
              value={address.postalCode}
              onChange={(event) => updateAddress('postalCode', event.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            />
          </div>
          <div>
            <label htmlFor="country" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
              Country (2-letter)
            </label>
            <input
              id="country"
              maxLength={2}
              value={address.country}
              onChange={(event) => updateAddress('country', event.target.value.toUpperCase())}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm uppercase text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            />
          </div>
        </div>

        <div>
          <label htmlFor="phone" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
            Phone (optional)
          </label>
          <input
            id="phone"
            value={address.phone}
            onChange={(event) => updateAddress('phone', event.target.value)}
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
          />
        </div>
      </fieldset>

      <fieldset disabled={isSubmitting} className="space-y-3 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <legend className="text-sm font-semibold text-slate-900 dark:text-slate-100">Payment</legend>
        <div className="rounded-md border border-slate-300 px-3 py-3 dark:border-slate-700">
          <CardElement options={{ hidePostalCode: true }} />
        </div>
        <p className="text-xs text-slate-400 dark:text-slate-500">
          Card details are handled directly by Stripe and never reach this application's own servers.
        </p>
      </fieldset>

      {errorMessage && (
        <div role="alert" className="space-y-2 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-400">
          <p>{errorMessage}</p>
          {status === 'orderError' && (
            <Link
              to={`/store/${storeId}/cart`}
              className="font-medium text-red-700 underline hover:text-red-600 dark:text-red-300"
            >
              Review your cart
            </Link>
          )}
        </div>
      )}

      <button
        type="submit"
        disabled={isSubmitting || !stripe || !elements || items.length === 0}
        className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {isSubmitting ? 'Processing…' : `Pay ${authoritativeTotal ? `$${authoritativeTotal}` : ''}`}
      </button>
    </form>
  )
}
