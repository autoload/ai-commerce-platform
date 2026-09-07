import { expect, type Page } from '@playwright/test'
import { test } from '@playwright/test'

// Phase 8F — integration coverage proving the SEAMS between the already
// individually-tested Phase 8 blocks (8A catalog, 8B/revision cart, 8C
// auth, 8D checkout, 8E order history/detail) actually connect in one
// continuous browser session. Every other spec in this project tests its
// own block against an independently-seeded starting state (a cart
// injected directly, an order mocked directly); this file deliberately
// does neither — the product is added through the real ProductDetailPage
// UI, the login/register form is the real one, and the merge/checkout/
// order-detail data is asserted to correspond to what actually happened
// earlier in the same test, not to a freshly-invented fixture.
//
// Same mocking discipline as every existing spec: the backend API and
// Stripe.js are both mocked at the network boundary — no live backend,
// database, or Stripe account anywhere in this file.

const STORE_ID = 970

const PRODUCT = {
  id: 1,
  variantId: 10,
  name: 'Widget',
  slug: 'widget',
  sku: 'W-1',
  price: '12.50',
}

const CATALOG_PRODUCT = {
  id: PRODUCT.id,
  name: PRODUCT.name,
  slug: PRODUCT.slug,
  description: null,
  category: null,
  images: [],
  options: [],
  variants: [{ id: PRODUCT.variantId, sku: PRODUCT.sku, price: PRODUCT.price, compare_at_price: null, in_stock: true, options: [] }],
}

const CUSTOMER = { id: 1, name: 'Jane', email: 'jane@example.com', phone: null, store_id: STORE_ID }

async function installFakeStripe(page: Page) {
  await page.addInitScript(() => {
    class FakeCardElement {
      mount(target: string | HTMLElement) {
        const el = typeof target === 'string' ? document.querySelector(target) : target
        if (el) el.innerHTML = '<div data-testid="fake-card-element">Fake card input</div>'
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
    // @ts-expect-error test-only global, deliberately loose
    window.Stripe = () => ({
      elements: () => new FakeElements(),
      confirmCardPayment: async () => ({ paymentIntent: { status: 'succeeded', id: 'pi_fake_journey' } }),
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

async function mockCatalogDetail(page: Page) {
  await page.route(new RegExp(`/api/shop/stores/${STORE_ID}/products/${PRODUCT.id}$`), (route) =>
    route.fulfill({ json: { data: CATALOG_PRODUCT } }),
  )
}

async function mockCustomerAuth(page: Page) {
  await page.route('**/api/customers/auth/login', (route) =>
    route.fulfill({ json: { token: 'fake-customer-token', customer: CUSTOMER } }),
  )
  await page.route('**/api/customers/auth/me', (route) =>
    route.fulfill({ json: { customer: CUSTOMER, store: { id: STORE_ID, name: 'Test Store' } } }),
  )
}

type ApiCartItem = {
  product_id: number
  product_variant_id: number
  product_name: string
  sku: string
  price: string
  compare_at_price: string | null
  in_stock: boolean
  options: unknown[]
  quantity: number
  line_total: string
}

function hydrate(quantity: number): ApiCartItem {
  return {
    product_id: PRODUCT.id,
    product_variant_id: PRODUCT.variantId,
    product_name: PRODUCT.name,
    sku: PRODUCT.sku,
    price: PRODUCT.price,
    compare_at_price: null,
    in_stock: true,
    options: [],
    quantity,
    line_total: (Number(PRODUCT.price) * quantity).toFixed(2),
  }
}

// Stateful authenticated-cart mock (GET/POST items/merge/DELETE), the same
// shape as authenticated-cart.spec.ts's own mock — duplicated locally
// rather than imported, matching this project's existing one-file-per-spec
// convention (no shared e2e utils module exists anywhere in this suite).
// `initialQuantity` seeds a PRE-EXISTING authenticated-cart line so the
// merge assertion below proves summing, not mere adoption.
function mockAuthenticatedCartApi(page: Page, initialQuantity: number) {
  let quantity = initialQuantity
  let mergeRequestBody: { items: { product_variant_id: number; quantity: number }[] } | null = null

  const respond = (route: Parameters<Parameters<Page['route']>[1]>[0]) => {
    const items = quantity > 0 ? [hydrate(quantity)] : []
    return route.fulfill({
      json: { data: { items, subtotal: items[0]?.line_total ?? '0.00', currency: 'usd' } },
    })
  }

  page.route('**/api/cart/merge', async (route) => {
    mergeRequestBody = route.request().postDataJSON()
    for (const line of mergeRequestBody?.items ?? []) {
      if (line.product_variant_id === PRODUCT.variantId) quantity += line.quantity
    }
    await respond(route)
  })

  page.route('**/api/cart/items', async (route) => {
    const { quantity: addQty } = route.request().postDataJSON() as { quantity: number }
    quantity += addQty
    await respond(route)
  })

  page.route('**/api/cart', async (route) => {
    if (route.request().method() === 'DELETE') {
      quantity = 0
      await route.fulfill({ status: 204 })
      return
    }
    await respond(route)
  })

  return {
    getMergeRequestBody: () => mergeRequestBody,
  }
}

// Echoes the real /api/checkout request body back into a realistic
// response — the order's item quantity/price genuinely reflects what the
// client actually submitted, not a value hand-picked independently of it.
function mockCheckout(page: Page, orderId: number) {
  return page.route('**/api/checkout', async (route) => {
    const body = route.request().postDataJSON() as {
      items: { product_variant_id: number; quantity: number }[]
    }
    const line = body.items[0]
    const lineTotal = (Number(PRODUCT.price) * line.quantity).toFixed(2)

    await route.fulfill({
      status: 201,
      json: {
        data: {
          id: orderId,
          store_id: STORE_ID,
          order_number: 'ORD-JOURNEY',
          status: 'pending',
          status_reason: null,
          subtotal: lineTotal,
          discount_total: '0.00',
          tax_total: '0.00',
          total: lineTotal,
          currency: 'usd',
          customer_name: CUSTOMER.name,
          customer_email: CUSTOMER.email,
          paid_at: null,
          cancelled_at: null,
          created_at: null,
          updated_at: null,
          items: [],
          shipping_address: null,
        },
        payment: { client_secret: 'pi_fake_secret_journey', stripe_payment_intent_id: 'pi_fake_journey' },
      },
    })

    // Order Detail's own mock (below) is seeded with this same quantity —
    // both derive from the one real request, not two independent fixtures.
    lastCheckoutQuantity = line.quantity
  })
}

let lastCheckoutQuantity = 0

function mockOrderDetail(page: Page, orderId: number) {
  return page.route(`**/api/customers/orders/${orderId}`, (route) =>
    route.fulfill({
      json: {
        data: {
          id: orderId,
          order_number: 'ORD-JOURNEY',
          status: 'pending',
          status_reason: null,
          subtotal: (Number(PRODUCT.price) * lastCheckoutQuantity).toFixed(2),
          discount_total: '0.00',
          tax_total: '0.00',
          total: (Number(PRODUCT.price) * lastCheckoutQuantity).toFixed(2),
          currency: 'usd',
          payment_status: 'processing',
          paid_at: null,
          cancelled_at: null,
          created_at: '2026-09-06T12:00:00Z',
          items: [
            {
              product_id: PRODUCT.id,
              product_variant_id: PRODUCT.variantId,
              product_name: PRODUCT.name,
              sku: PRODUCT.sku,
              unit_price: PRODUCT.price,
              quantity: lastCheckoutQuantity,
              line_total: (Number(PRODUCT.price) * lastCheckoutQuantity).toFixed(2),
              selected_options: null,
            },
          ],
          shipping_address: {
            recipient_name: 'Jane Doe',
            line1: '123 Main St',
            line2: null,
            city: 'Springfield',
            state: 'IL',
            postal_code: '62701',
            country: 'US',
            phone: null,
          },
        },
      },
    }),
  )
}

async function fillAddress(page: Page) {
  await page.getByLabel('Full name').fill('Jane Doe')
  await page.getByLabel('Address line 1').fill('123 Main St')
  await page.getByLabel('City').fill('Springfield')
  await page.getByLabel('State').fill('IL')
  await page.getByLabel('Postal code').fill('62701')
  await page.getByLabel('Country (2-letter)').fill('US')
}

test('guest journey: catalog -> add to cart -> login -> cart merge -> checkout -> order confirmation -> order detail', async ({
  page,
}) => {
  const ORDER_ID = 701

  await installFakeStripe(page)
  await mockCatalogDetail(page)
  await mockCustomerAuth(page)
  // Existing authenticated cart already holds 1 of this variant — proves
  // the merge SUMS into it rather than merely adopting the guest cart.
  const cartMock = mockAuthenticatedCartApi(page, 1)
  await mockCheckout(page, ORDER_ID)
  await mockOrderDetail(page, ORDER_ID)

  // Catalog -> Product -> real "Add to cart" UI (guest, localStorage).
  await page.goto(`/store/${STORE_ID}/products/${PRODUCT.id}`)
  await page.getByRole('button', { name: 'Add to cart' }).click()
  await expect(page.getByRole('status')).toBeVisible()

  // Guest Cart: bump quantity to 2 via the real quantity stepper, so the
  // merge below is summing a non-trivial (2), not a trivial (1), quantity.
  await page.getByRole('link', { name: /^Cart/ }).click()
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/cart$`))
  await page.getByRole('button', { name: `Increase quantity for ${PRODUCT.name}` }).click()
  await expect(page.getByRole('spinbutton', { name: `Quantity for ${PRODUCT.name}` })).toHaveValue('2')

  // Login via the real form, reached via real storefront navigation.
  await page.getByRole('link', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/login$`))
  await page.getByLabel('Email').fill(CUSTOMER.email)
  await page.getByLabel('Password').fill('password123')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}$`))

  // Guest Cart Merge actually happened: the real POST /api/cart/merge body
  // carried exactly the guest cart just built (variant/qty 2)...
  await expect.poll(() => cartMock.getMergeRequestBody()).toEqual({
    items: [{ product_variant_id: PRODUCT.variantId, quantity: 2 }],
  })
  // ...and the resulting Authenticated Cart shows the SUM (1 existing + 2
  // guest = 3), not either quantity alone.
  await page.getByRole('link', { name: /^Cart/ }).click()
  await expect(page.getByRole('spinbutton', { name: `Quantity for ${PRODUCT.name}` })).toHaveValue('3')

  // Checkout, reached via real cart -> checkout navigation.
  await page.getByRole('link', { name: 'Checkout', exact: true }).click()
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/checkout$`))
  await expect(page.getByText(`${PRODUCT.name} × 3`)).toBeVisible()

  await fillAddress(page)
  await page.getByRole('button', { name: /Pay/ }).click()

  // Order Confirmation, reached via the real post-checkout navigation
  // (never page.goto) -> Order Detail, fetched from the authenticated API.
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/orders/${ORDER_ID}$`))
  await expect(page.getByRole('status')).toContainText('Your order has been placed')

  // The rendered order corresponds to what was actually built earlier in
  // THIS journey — quantity 3 (the merged total), this product's name/SKU,
  // and a total derived from that same quantity × this product's price —
  // not an independently-invented fixture.
  await expect(page.getByRole('heading', { name: 'ORD-JOURNEY' })).toBeVisible()
  await expect(page.getByText(PRODUCT.name)).toBeVisible()
  await expect(page.getByText(`${PRODUCT.sku} · qty 3`, { exact: false })).toBeVisible()
  // Both the Subtotal and Total lines correctly show this amount (no
  // discount/tax in this fixture) — two matches, not one, is correct here.
  await expect(page.getByText(`$${(Number(PRODUCT.price) * 3).toFixed(2)} USD`)).toHaveCount(2)
})

test('authenticated customer journey: login -> add to cart -> checkout -> order confirmation -> order detail', async ({
  page,
}) => {
  const ORDER_ID = 702

  await installFakeStripe(page)
  await mockCatalogDetail(page)
  await mockCustomerAuth(page)
  // Nothing in the authenticated cart yet — this journey never touches
  // the guest cart or the merge endpoint at all.
  mockAuthenticatedCartApi(page, 0)
  await mockCheckout(page, ORDER_ID)
  await mockOrderDetail(page, ORDER_ID)

  let mergeCalled = false
  await page.route('**/api/cart/merge', async (route) => {
    mergeCalled = true
    await route.fallback()
  })

  // Login first via the real form — this customer is authenticated before
  // ever touching the cart, unlike the guest journey above.
  await page.goto(`/store/${STORE_ID}/login`)
  await page.getByLabel('Email').fill(CUSTOMER.email)
  await page.getByLabel('Password').fill('password123')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}$`))

  // Add to cart while already authenticated — this exercises the API-
  // backed branch of CartContext directly (POST /api/cart/items), a
  // different code path from the guest journey's localStorage branch.
  await page.goto(`/store/${STORE_ID}/products/${PRODUCT.id}`)
  await page.getByRole('button', { name: 'Add to cart' }).click()
  await expect(page.getByRole('status')).toBeVisible()

  await page.getByRole('link', { name: /^Cart/ }).click()
  await expect(page.getByRole('spinbutton', { name: `Quantity for ${PRODUCT.name}` })).toHaveValue('1')

  await page.getByRole('link', { name: 'Checkout', exact: true }).click()
  await fillAddress(page)
  await page.getByRole('button', { name: /Pay/ }).click()

  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/orders/${ORDER_ID}$`))
  await expect(page.getByText(`${PRODUCT.sku} · qty 1`, { exact: false })).toBeVisible()
  await expect(page.getByText(`$${PRODUCT.price} USD`)).toHaveCount(2)

  // No guest cart ever existed in this journey, so no merge call should
  // ever have been made.
  expect(mergeCalled).toBe(false)
})
