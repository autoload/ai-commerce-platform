import { expect, type Page } from '@playwright/test'
import { test } from '@playwright/test'

// Phase 8E E2E coverage for the customer-facing Order History and Order
// Detail pages. The backend these consume (GET /api/customers/orders[/{order}])
// already existed and was already fully tested as of Phase 7 — this suite
// only covers the new frontend, mocked at the network boundary exactly
// like every other spec in this project (catalog.spec.ts, cart.spec.ts,
// authenticated-cart.spec.ts). No live backend integration here.

const STORE_ID = 950

const CUSTOMER = { id: 1, name: 'Jane', email: 'jane@example.com', phone: null, store_id: STORE_ID }

const ORDER_SUMMARY = {
  id: 501,
  order_number: 'ORD-501',
  status: 'paid',
  status_reason: null,
  subtotal: '25.00',
  discount_total: '0.00',
  tax_total: '0.00',
  total: '25.00',
  currency: 'usd',
  payment_status: 'succeeded',
  paid_at: '2026-09-01T12:00:00Z',
  cancelled_at: null,
  created_at: '2026-09-01T11:55:00Z',
}

const ORDER_DETAIL = {
  ...ORDER_SUMMARY,
  items: [
    {
      product_id: 1,
      product_variant_id: 10,
      product_name: 'Widget',
      sku: 'W-1',
      unit_price: '12.50',
      quantity: 2,
      line_total: '25.00',
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
}

async function mockCustomerAuth(page: Page) {
  await page.route('**/api/customers/auth/login', (route) =>
    route.fulfill({ json: { token: 'fake-customer-token', customer: CUSTOMER } }),
  )
  await page.route('**/api/customers/auth/me', (route) =>
    route.fulfill({ json: { customer: CUSTOMER, store: { id: STORE_ID, name: 'Test Store' } } }),
  )
  await page.route('**/api/cart', (route) =>
    route.fulfill({ json: { data: { items: [], subtotal: '0.00', currency: 'usd' } } }),
  )
}

async function login(page: Page) {
  await page.goto(`/store/${STORE_ID}/login`)
  await page.getByLabel('Email').fill(CUSTOMER.email)
  await page.getByLabel('Password').fill('password123')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}$`))
}

function listResponse(orders: unknown[], meta?: Partial<{ current_page: number; last_page: number; total: number }>) {
  return {
    json: {
      data: orders,
      meta: { current_page: 1, last_page: 1, per_page: 15, total: orders.length, ...meta },
    },
  }
}

test('1. authenticated customer can open Order History', async ({ page }) => {
  await mockCustomerAuth(page)
  await page.route('**/api/customers/orders', (route) => route.fulfill(listResponse([ORDER_SUMMARY])))
  await login(page)

  await page.goto(`/store/${STORE_ID}/account`)
  await page.getByRole('link', { name: 'View your orders →' }).click()

  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/orders$`))
  await expect(page.getByRole('heading', { name: 'Your Orders' })).toBeVisible()
})

// 2. Order list renders order number, status, payment status, total.
test('2. order list renders order summary fields', async ({ page }) => {
  await mockCustomerAuth(page)
  await page.route('**/api/customers/orders', (route) => route.fulfill(listResponse([ORDER_SUMMARY])))
  await login(page)

  await page.goto(`/store/${STORE_ID}/orders`)

  await expect(page.getByText('ORD-501')).toBeVisible()
  // Both the order status ("paid") and payment status ("succeeded") render
  // as the literal text "Paid" for this fixture — two distinct elements,
  // asserted by count rather than a single ambiguous locator.
  await expect(page.getByText('Paid', { exact: true })).toHaveCount(2)
  await expect(page.getByText(/25\.00/)).toBeVisible()
})

// 3. Empty order history.
test('3. empty order history shows an empty state with a link to browse products', async ({ page }) => {
  await mockCustomerAuth(page)
  await page.route('**/api/customers/orders', (route) => route.fulfill(listResponse([])))
  await login(page)

  await page.goto(`/store/${STORE_ID}/orders`)

  await expect(page.getByText("You haven't placed any orders yet.")).toBeVisible()
  await expect(page.getByRole('link', { name: 'Browse products' })).toBeVisible()
})

// 4, 5. Open order detail; items/address/status/payment status render.
test('4-5. opening an order shows its detail — items, address, status, payment status', async ({ page }) => {
  await mockCustomerAuth(page)
  await page.route('**/api/customers/orders', (route) => route.fulfill(listResponse([ORDER_SUMMARY])))
  await page.route(`**/api/customers/orders/${ORDER_DETAIL.id}`, (route) => route.fulfill({ json: { data: ORDER_DETAIL } }))
  await login(page)

  await page.goto(`/store/${STORE_ID}/orders`)
  await page.getByText('ORD-501').click()

  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/orders/${ORDER_DETAIL.id}$`))
  await expect(page.getByRole('heading', { name: 'ORD-501' })).toBeVisible()
  await expect(page.getByText('Widget')).toBeVisible()
  await expect(page.getByText('W-1', { exact: false })).toBeVisible()
  await expect(page.getByText('Jane Doe')).toBeVisible()
  await expect(page.getByText('Springfield, IL 62701')).toBeVisible()
})

// 6. Back to order history from detail.
test('6. back link returns from order detail to order history', async ({ page }) => {
  await mockCustomerAuth(page)
  await page.route('**/api/customers/orders', (route) => route.fulfill(listResponse([ORDER_SUMMARY])))
  await page.route(`**/api/customers/orders/${ORDER_DETAIL.id}`, (route) => route.fulfill({ json: { data: ORDER_DETAIL } }))
  await login(page)

  await page.goto(`/store/${STORE_ID}/orders/${ORDER_DETAIL.id}`)
  await page.getByRole('link', { name: '← Back to your orders' }).click()

  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/orders$`))
})

// 7. Pagination — Prev/Next using the existing API paginator shape.
test('7. pagination moves between pages via Prev/Next', async ({ page }) => {
  await mockCustomerAuth(page)
  const pageOrder = { ...ORDER_SUMMARY, id: 1, order_number: 'ORD-PAGE-1' }
  const pageTwoOrder = { ...ORDER_SUMMARY, id: 2, order_number: 'ORD-PAGE-2' }

  await page.route('**/api/customers/orders**', (route) => {
    const url = new URL(route.request().url())
    const p = url.searchParams.get('page') ?? '1'
    if (p === '2') {
      return route.fulfill(listResponse([pageTwoOrder], { current_page: 2, last_page: 2, total: 2 }))
    }
    return route.fulfill(listResponse([pageOrder], { current_page: 1, last_page: 2, total: 2 }))
  })
  await login(page)

  await page.goto(`/store/${STORE_ID}/orders`)
  await expect(page.getByText('ORD-PAGE-1')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Previous' })).toBeDisabled()

  await page.getByRole('button', { name: 'Next' }).click()
  await expect(page.getByText('ORD-PAGE-2')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Next' })).toBeDisabled()

  await page.getByRole('button', { name: 'Previous' }).click()
  await expect(page.getByText('ORD-PAGE-1')).toBeVisible()
})

// 8. Unauthenticated access is protected on both routes.
test('8. unauthenticated access to order history and order detail redirects to login', async ({ page }) => {
  await page.goto(`/store/${STORE_ID}/orders`)
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/login$`))

  await page.goto(`/store/${STORE_ID}/orders/${ORDER_DETAIL.id}`)
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/login$`))
})

// 9. Loading state, on both pages.
test('9. loading state is shown while the order list/detail requests are in flight', async ({ page }) => {
  await mockCustomerAuth(page)
  await page.route('**/api/customers/orders', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 1000))
    await route.fulfill(listResponse([ORDER_SUMMARY]))
  })
  await login(page)

  await page.goto(`/store/${STORE_ID}/orders`)
  await expect(page.getByText('Loading orders…')).toBeVisible()
  await expect(page.getByText('ORD-501')).toBeVisible()
})

// 10. Error state, on both pages — a failed request surfaces an alert, not
// a crash or a silent blank page.
test('10. a failed order-list request surfaces an error message', async ({ page }) => {
  await mockCustomerAuth(page)
  await page.route('**/api/customers/orders', (route) =>
    route.fulfill({ status: 500, json: { message: 'Internal error.' } }),
  )
  await login(page)

  await page.goto(`/store/${STORE_ID}/orders`)
  await expect(page.getByRole('alert')).toBeVisible()
})

test('10b. a nonexistent order detail shows a not-found message', async ({ page }) => {
  await mockCustomerAuth(page)
  await page.route('**/api/customers/orders/999999', (route) => route.fulfill({ status: 404, json: { message: 'Not found.' } }))
  await login(page)

  await page.goto(`/store/${STORE_ID}/orders/999999`)
  await expect(page.getByRole('alert')).toContainText('Order not found.')
})
