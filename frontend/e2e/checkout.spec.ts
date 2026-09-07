import { expect, type Page } from '@playwright/test'
import { test } from '@playwright/test'

// Block 8D E2E coverage for storefront checkout. As with catalog.spec.ts,
// customer-auth.spec.ts, and cart.spec.ts, there is no seed/fixture data or
// live Stripe account available, so both the backend API and Stripe.js
// itself are mocked deterministically — no real Stripe account, live
// Stripe API, real card data, or real PaymentIntents are used anywhere in
// this suite (per the approved Block 8D testing constraints).
//
// Stripe.js is faked by pre-defining `window.Stripe` via an init script,
// before the app's own `loadStripe()` call runs: @stripe/stripe-js's
// loadStripe() returns the existing `window.Stripe` global immediately if
// one is already present, instead of injecting the real js.stripe.com
// script — a well-established technique for testing Stripe Elements
// integrations without a live Stripe environment. The fake object's shape
// was verified empirically against @stripe/react-stripe-js's <Elements>
// prop validation (it duck-types the `stripe` prop) during development of
// this file — trimming it back down would need to be re-verified the same
// way, not assumed safe.
//
// Phase 8B revision: the customer in this suite is always authenticated
// (checkout requires it), so its cart is now the Redis-backed /api/cart —
// never localStorage. mockCart() below stands in for that endpoint with
// simple in-memory state (GET returns it, DELETE clears it, matching
// CheckoutForm's real clearCart()-on-confirmed-success call). It replaces
// the previous approach of writing the guest localStorage cart directly,
// which no longer has any effect on what an authenticated customer's
// useCart() reads.

const STORE_ID = 800

const CUSTOMER = { id: 1, name: 'Jane', email: 'jane@example.com', phone: null, store_id: STORE_ID }

const CART_ITEM = {
  productId: 1,
  variantId: 1,
  productName: 'Widget',
  variantLabel: '',
  sku: 'W-1',
  displayPrice: '12.50',
  quantity: 1,
  inStockAtAdd: true,
}

async function installFakeStripe(page: Page) {
  await page.addInitScript(() => {
    class FakeCardElement {
      mount(target: string | HTMLElement) {
        const el = typeof target === 'string' ? document.querySelector(target) : target
        if (el) {
          el.innerHTML = '<div data-testid="fake-card-element">Fake card input</div>'
        }
      }
      on() {}
      unmount() {}
      update() {}
    }

    class FakeElements {
      private card: FakeCardElement | null = null
      create() {
        this.card = new FakeCardElement()
        return this.card
      }
      getElement() {
        return this.card
      }
    }

    // A client_secret containing "DECLINE" drives the fake confirmation to
    // fail, and one containing "PROCESSING" resolves with PaymentIntent
    // status "processing" (Stripe's own non-terminal, still-being-confirmed
    // state) — both set by the mocked /api/checkout response per test,
    // exactly like real Stripe test-mode client_secrets would.
    // @ts-expect-error test-only global, deliberately loose
    window.Stripe = () => ({
      elements: () => new FakeElements(),
      confirmCardPayment: async (clientSecret: string) => {
        if (clientSecret.includes('DECLINE')) {
          return { error: { message: 'Your card was declined.' } }
        }
        if (clientSecret.includes('PROCESSING')) {
          return { paymentIntent: { status: 'processing', id: 'pi_fake_123' } }
        }
        return { paymentIntent: { status: 'succeeded', id: 'pi_fake_123' } }
      },
      createToken: async () => ({ token: { id: 'tok_fake' } }),
      createPaymentMethod: async () => ({ paymentMethod: { id: 'pm_fake' } }),
      confirmCardSetup: async () => ({ setupIntent: { status: 'succeeded' } }),
      confirmPayment: async () => ({ paymentIntent: { status: 'succeeded' } }),
      paymentRequest: () => ({ canMakePayment: async () => null, on: () => {}, off: () => {} }),
      registerAppInfo: () => {},
      _registerWrapper: () => {},
      retrievePaymentIntent: async () => ({ paymentIntent: { status: 'succeeded' } }),
    })
  })
}

async function loginAsCustomer(page: Page) {
  await page.route('**/api/customers/auth/login', (route) =>
    route.fulfill({ json: { token: 'fake-token', customer: CUSTOMER } }),
  )
  await page.route('**/api/customers/auth/me', (route) =>
    route.fulfill({ json: { customer: CUSTOMER, store: { id: STORE_ID, name: 'Test Store' } } }),
  )

  await page.goto(`/store/${STORE_ID}/login`)
  await page.getByLabel('Email').fill(CUSTOMER.email)
  await page.getByLabel('Password').fill('password123')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}$`))
}

function toCartApiItem(item: typeof CART_ITEM) {
  return {
    product_id: item.productId,
    product_variant_id: item.variantId,
    product_name: item.productName,
    sku: item.sku,
    price: item.displayPrice,
    compare_at_price: null,
    in_stock: item.inStockAtAdd,
    options: [],
    quantity: item.quantity,
    line_total: (Number(item.displayPrice) * item.quantity).toFixed(2),
  }
}

// Stands in for the authenticated Redis-backed cart. Must be called BEFORE
// loginAsCustomer(), not after — CartContext's GET /api/cart fires as soon
// as login resolves to 'authenticated', so registering this route only
// after login risks a race against the real (unmocked) request.
async function mockCart(page: Page, items: (typeof CART_ITEM)[] = [CART_ITEM]) {
  let state = items.map(toCartApiItem)

  await page.route('**/api/cart', async (route) => {
    if (route.request().method() === 'DELETE') {
      state = []
      await route.fulfill({ status: 204 })
      return
    }

    const subtotal = state.reduce((sum, i) => sum + Number(i.line_total), 0).toFixed(2)
    await route.fulfill({ json: { data: { items: state, subtotal, currency: 'usd' } } })
  })
}

function checkoutSuccessResponse(clientSecret: string) {
  return {
    status: 201 as const,
    json: {
      data: {
        id: 55,
        store_id: STORE_ID,
        order_number: 'ORD-1',
        status: 'pending',
        status_reason: null,
        subtotal: '12.50',
        discount_total: '0.00',
        tax_total: '0.00',
        total: '12.50',
        currency: 'usd',
        customer_name: 'Jane',
        customer_email: 'jane@example.com',
        paid_at: null,
        cancelled_at: null,
        created_at: null,
        updated_at: null,
        items: [],
        shipping_address: null,
      },
      payment: { client_secret: clientSecret, stripe_payment_intent_id: 'pi_fake_123' },
    },
  }
}

function mockCheckoutSuccess(page: Page, clientSecret = 'pi_fake_secret_SUCCEED') {
  return page.route('**/api/checkout', (route) => route.fulfill(checkoutSuccessResponse(clientSecret)))
}

async function fillAddress(page: Page) {
  await page.getByLabel('Full name').fill('Jane Doe')
  await page.getByLabel('Address line 1').fill('123 Main St')
  await page.getByLabel('City').fill('Springfield')
  await page.getByLabel('State').fill('IL')
  await page.getByLabel('Postal code').fill('62701')
  await page.getByLabel('Country (2-letter)').fill('US')
}

test('A. the checkout page is protected — an unauthenticated visitor is redirected to login', async ({ page }) => {
  await page.goto(`/store/${STORE_ID}/checkout`)

  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/login$`))
})

test('B. navigating from the cart to checkout works', async ({ page }) => {
  await installFakeStripe(page)
  await mockCart(page)
  await loginAsCustomer(page)

  await page.goto(`/store/${STORE_ID}/cart`)
  await page.getByRole('link', { name: 'Checkout', exact: true }).click()

  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/checkout$`))
  await expect(page.getByRole('heading', { name: 'Checkout' })).toBeVisible()
})

test('C. checkout renders the cart summary with an estimated total', async ({ page }) => {
  await installFakeStripe(page)
  await mockCart(page)
  await loginAsCustomer(page)

  await page.goto(`/store/${STORE_ID}/checkout`)

  await expect(page.getByText('Widget × 1')).toBeVisible()
  await expect(page.getByText('Estimated total')).toBeVisible()
  await expect(page.getByTestId('fake-card-element')).toBeVisible()
})

test('D. checkout sends only variant ids and quantities, never display price or subtotal', async ({ page }) => {
  await installFakeStripe(page)
  await mockCart(page)
  await loginAsCustomer(page)

  let requestBody: unknown = null
  await page.route('**/api/checkout', async (route) => {
    requestBody = route.request().postDataJSON()
    await route.fulfill(checkoutSuccessResponse('pi_fake_secret_SUCCEED'))
  })

  await page.goto(`/store/${STORE_ID}/checkout`)
  await fillAddress(page)
  await page.getByRole('button', { name: /Pay/ }).click()
  await expect(page.getByRole('status')).toBeVisible()

  expect(requestBody).toEqual({
    items: [{ product_variant_id: CART_ITEM.variantId, quantity: CART_ITEM.quantity }],
    shipping_address: {
      recipient_name: 'Jane Doe',
      line1: '123 Main St',
      city: 'Springfield',
      state: 'IL',
      postal_code: '62701',
      country: 'US',
    },
  })
})

test('E. a successful payment reaches the success state and clears the cart', async ({ page }) => {
  await installFakeStripe(page)
  await mockCart(page)
  await loginAsCustomer(page)
  await mockCheckoutSuccess(page)

  await page.goto(`/store/${STORE_ID}/checkout`)
  await fillAddress(page)
  await page.getByRole('button', { name: /Pay/ }).click()

  await expect(page.getByRole('status')).toBeVisible()
  await expect(page.getByText('Payment successful')).toBeVisible()
  await expect(page.getByText('ORD-1')).toBeVisible()

  await page.goto(`/store/${STORE_ID}/cart`)
  await expect(page.getByText('Your cart is empty.')).toBeVisible()
})

test('F. a backend validation failure is displayed and the cart is preserved', async ({ page }) => {
  await installFakeStripe(page)
  await mockCart(page)
  await loginAsCustomer(page)
  await page.route('**/api/checkout', (route) =>
    route.fulfill({ status: 422, json: { message: 'Product variant 1 is unavailable for checkout: is not active.' } }),
  )

  await page.goto(`/store/${STORE_ID}/checkout`)
  await fillAddress(page)
  await page.getByRole('button', { name: /Pay/ }).click()

  await expect(page.getByRole('alert')).toContainText('unavailable for checkout')
  await expect(page.getByRole('link', { name: 'Review your cart' })).toBeVisible()
  await expect(page.getByRole('button', { name: /Pay/ })).toBeEnabled()

  await page.goto(`/store/${STORE_ID}/cart`)
  await expect(page.getByText('Widget')).toBeVisible()
})

test('G. a Stripe payment failure is displayed and the cart is preserved', async ({ page }) => {
  await installFakeStripe(page)
  await mockCart(page)
  await loginAsCustomer(page)
  await mockCheckoutSuccess(page, 'pi_fake_secret_DECLINE')

  await page.goto(`/store/${STORE_ID}/checkout`)
  await fillAddress(page)
  await page.getByRole('button', { name: /Pay/ }).click()

  await expect(page.getByRole('alert')).toContainText('Your card was declined.')
  await expect(page.getByRole('button', { name: /Pay/ })).toBeEnabled()

  await page.goto(`/store/${STORE_ID}/cart`)
  await expect(page.getByText('Widget')).toBeVisible()
})

test('H. the Pay button disables itself while a submission is in flight', async ({ page }) => {
  await installFakeStripe(page)
  await mockCart(page)
  await loginAsCustomer(page)

  await page.route('**/api/checkout', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 500))
    await route.fulfill(checkoutSuccessResponse('pi_fake_secret_SUCCEED'))
  })

  await page.goto(`/store/${STORE_ID}/checkout`)
  await fillAddress(page)

  // The button's own label switches from "Pay …" to "Processing…" once
  // clicked, so it's located by type rather than by its (changing) name.
  const payButton = page.locator('form button[type="submit"]')
  await payButton.click()

  await expect(payButton).toBeDisabled()
  await expect(payButton).toHaveText('Processing…')
  await expect(page.getByRole('status')).toBeVisible({ timeout: 10000 })
})

test('I. a PaymentIntent status of "processing" shows a payment-pending state, not success, and does not clear the cart', async ({
  page,
}) => {
  await installFakeStripe(page)
  await mockCart(page)
  await loginAsCustomer(page)
  await mockCheckoutSuccess(page, 'pi_fake_secret_PROCESSING')

  await page.goto(`/store/${STORE_ID}/checkout`)
  await fillAddress(page)
  await page.getByRole('button', { name: /Pay/ }).click()

  await expect(page.getByRole('status')).toBeVisible()
  await expect(page.getByText('Payment processing')).toBeVisible()
  await expect(page.getByText('Payment successful')).toHaveCount(0)

  // Not yet a confirmed success — the cart must still be intact.
  await page.goto(`/store/${STORE_ID}/cart`)
  await expect(page.getByText('Widget')).toBeVisible()
})

test('J. changing the shipping address between two submit attempts uses a new idempotency key; an identical resubmission reuses it', async ({
  page,
}) => {
  await installFakeStripe(page)
  await mockCart(page)
  await loginAsCustomer(page)

  const idempotencyKeys: (string | null)[] = []
  await page.route('**/api/checkout', (route) => {
    idempotencyKeys.push(route.request().headers()['idempotency-key'] ?? null)
    return route.fulfill({ status: 422, json: { message: 'Something changed. Please review your cart.' } })
  })

  await page.goto(`/store/${STORE_ID}/checkout`)
  await fillAddress(page)

  await page.getByRole('button', { name: /Pay/ }).click()
  await expect(page.getByRole('alert')).toBeVisible()

  // Identical resubmission (no changes) must reuse the same key — this is
  // the ordinary "retry" case and must not be treated as a new attempt.
  await page.getByRole('button', { name: /Pay/ }).click()
  await expect(page.getByRole('alert')).toBeVisible()

  expect(idempotencyKeys).toHaveLength(2)
  expect(idempotencyKeys[1]).toBe(idempotencyKeys[0])

  // Now change the shipping address (a materially different payload) and
  // resubmit — this must be treated as a new checkout attempt, not a retry
  // of the old one.
  await page.getByLabel('City').fill('Chicago')
  await page.getByRole('button', { name: /Pay/ }).click()
  await expect(page.getByRole('alert')).toBeVisible()

  expect(idempotencyKeys).toHaveLength(3)
  expect(idempotencyKeys[2]).not.toBe(idempotencyKeys[1])
})
