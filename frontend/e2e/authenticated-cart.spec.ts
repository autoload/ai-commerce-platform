import { expect, type Page } from '@playwright/test'
import { test } from '@playwright/test'

// Phase 8B revision E2E coverage for the AUTHENTICATED (Redis-backed via
// /api/cart*) cart — complementing cart.spec.ts (Block 8B), which covers
// only the guest/localStorage path and is deliberately left unmodified.
// As with every other spec in this project, there is no seed/fixture data
// or live backend integration here: the customer-auth and cart endpoints
// are mocked deterministically with simple in-memory state per test.

const STORE_ID = 900

const CUSTOMER = { id: 1, name: 'Jane', email: 'jane@example.com', phone: null, store_id: STORE_ID }

const VARIANT_A = { id: 10, product_id: 1, product_name: 'Widget', sku: 'W-1', price: '12.50' }
const VARIANT_B = { id: 20, product_id: 2, product_name: 'Gadget', sku: 'G-1', price: '7.00' }

const CATALOG_PRODUCT_A = {
  id: VARIANT_A.product_id,
  name: VARIANT_A.product_name,
  slug: 'widget',
  description: null,
  category: null,
  images: [],
  options: [],
  variants: [{ id: VARIANT_A.id, sku: VARIANT_A.sku, price: VARIANT_A.price, compare_at_price: null, in_stock: true, options: [] }],
}

type ApiItem = {
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

const KNOWN_VARIANTS: Record<number, typeof VARIANT_A> = { [VARIANT_A.id]: VARIANT_A, [VARIANT_B.id]: VARIANT_B }

function hydrate(variantId: number, quantity: number): ApiItem {
  const v = KNOWN_VARIANTS[variantId]
  return {
    product_id: v.product_id,
    product_variant_id: v.id,
    product_name: v.product_name,
    sku: v.sku,
    price: v.price,
    compare_at_price: null,
    in_stock: true,
    options: [],
    quantity,
    line_total: (Number(v.price) * quantity).toFixed(2),
  }
}

function subtotalOf(items: ApiItem[]): string {
  return items.reduce((sum, i) => sum + Number(i.line_total), 0).toFixed(2)
}

async function mockCustomerAuth(page: Page) {
  await page.route('**/api/customers/auth/login', (route) =>
    route.fulfill({ json: { token: 'fake-customer-token', customer: CUSTOMER } }),
  )
  await page.route('**/api/customers/auth/me', (route) =>
    route.fulfill({ json: { customer: CUSTOMER, store: { id: STORE_ID, name: 'Test Store' } } }),
  )
  await page.route('**/api/customers/auth/logout', (route) => route.fulfill({ json: { message: 'Logged out.' } }))
}

async function login(page: Page) {
  await page.goto(`/store/${STORE_ID}/login`)
  await page.getByLabel('Email').fill(CUSTOMER.email)
  await page.getByLabel('Password').fill('password123')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}$`))
}

// Stateful mock for the whole /api/cart* surface. Registered BEFORE login,
// same reasoning as checkout.spec.ts's mockCart(): the authenticated cart
// query fires as soon as login resolves.
async function mockAuthenticatedCartApi(page: Page, initial: ApiItem[] = []) {
  let items = [...initial]

  const respond = (route: Parameters<Parameters<Page['route']>[1]>[0]) =>
    route.fulfill({ json: { data: { items, subtotal: subtotalOf(items), currency: 'usd' } } })

  await page.route('**/api/cart/merge', async (route) => {
    const body = (route.request().postDataJSON() ?? {}) as { items?: { product_variant_id: number; quantity: number }[] }
    for (const line of body.items ?? []) {
      const existing = items.find((i) => i.product_variant_id === line.product_variant_id)
      if (existing) {
        existing.quantity += line.quantity
        existing.line_total = (Number(existing.price) * existing.quantity).toFixed(2)
      } else {
        items.push(hydrate(line.product_variant_id, line.quantity))
      }
    }
    await respond(route)
  })

  await page.route(/\/api\/cart\/items\/\d+$/, async (route) => {
    const variantId = Number(new URL(route.request().url()).pathname.split('/').pop())
    if (route.request().method() === 'PATCH') {
      const { quantity } = route.request().postDataJSON() as { quantity: number }
      const existing = items.find((i) => i.product_variant_id === variantId)
      if (existing) {
        existing.quantity = quantity
        existing.line_total = (Number(existing.price) * quantity).toFixed(2)
      }
    } else if (route.request().method() === 'DELETE') {
      items = items.filter((i) => i.product_variant_id !== variantId)
    }
    await respond(route)
  })

  await page.route('**/api/cart/items', async (route) => {
    const { product_variant_id, quantity } = route.request().postDataJSON() as {
      product_variant_id: number
      quantity: number
    }
    const existing = items.find((i) => i.product_variant_id === product_variant_id)
    if (existing) {
      existing.quantity += quantity
      existing.line_total = (Number(existing.price) * existing.quantity).toFixed(2)
    } else {
      items.push(hydrate(product_variant_id, quantity))
    }
    await respond(route)
  })

  await page.route('**/api/cart', async (route) => {
    if (route.request().method() === 'DELETE') {
      items = []
      await route.fulfill({ status: 204 })
      return
    }
    await respond(route)
  })
}

// 2. Authenticated cart loads from the API (not localStorage).
test('authenticated cart loads from the API, hydrated with live product data', async ({ page }) => {
  await mockAuthenticatedCartApi(page, [hydrate(VARIANT_A.id, 2)])
  await mockCustomerAuth(page)
  await login(page)

  await page.goto(`/store/${STORE_ID}/cart`)

  await expect(page.getByText('Widget')).toBeVisible()
  await expect(page.getByText('SKU W-1')).toBeVisible()
  await expect(page.getByTestId('cart-subtotal')).toHaveText('$25.00')
})

// 3. Authenticated add/update/remove all go through the API.
test('authenticated add, update, and remove all persist through the cart API', async ({ page }) => {
  await mockAuthenticatedCartApi(page, [])
  await mockCustomerAuth(page)
  await page.route(new RegExp(`/api/shop/stores/${STORE_ID}/products/\\d+$`), (route) =>
    route.fulfill({ json: { data: CATALOG_PRODUCT_A } }),
  )
  await login(page)

  await page.goto(`/store/${STORE_ID}/products/${CATALOG_PRODUCT_A.id}`)
  await page.getByRole('button', { name: 'Add to cart' }).click()
  await expect(page.getByRole('status')).toBeVisible()

  await page.goto(`/store/${STORE_ID}/cart`)
  await expect(page.getByText('Widget')).toBeVisible()

  await page.getByRole('button', { name: 'Increase quantity for Widget' }).click()
  await expect(page.getByRole('spinbutton', { name: 'Quantity for Widget' })).toHaveValue('2')
  await expect(page.getByTestId('cart-subtotal')).toHaveText('$25.00')

  await page.getByRole('button', { name: 'Remove' }).click()
  await expect(page.getByText('Your cart is empty.')).toBeVisible()
})

// 4, 5. Login merges the guest cart into the authenticated one, and the
// guest localStorage cart is cleared ONLY once the merge has succeeded.
test('login merges the guest cart into the authenticated cart, then clears localStorage', async ({ page }) => {
  await mockAuthenticatedCartApi(page, [hydrate(VARIANT_A.id, 3)]) // pre-existing authenticated cart: A x 3
  await mockCustomerAuth(page)

  // Build a guest cart of A x 2, B x 1 BEFORE logging in.
  await page.goto(`/store/${STORE_ID}`)
  await page.evaluate(
    ({ storeId, items }) => localStorage.setItem(`ai_commerce.cart.${storeId}`, JSON.stringify({ schemaVersion: 1, storeId, items })),
    {
      storeId: STORE_ID,
      items: [
        { productId: VARIANT_A.product_id, variantId: VARIANT_A.id, productName: 'Widget', variantLabel: '', sku: 'W-1', displayPrice: '12.50', quantity: 2, inStockAtAdd: true },
        { productId: VARIANT_B.product_id, variantId: VARIANT_B.id, productName: 'Gadget', variantLabel: '', sku: 'G-1', displayPrice: '7.00', quantity: 1, inStockAtAdd: true },
      ],
    },
  )

  await login(page)

  await page.goto(`/store/${STORE_ID}/cart`)
  // Merged result: A x (3 existing + 2 guest) = 5, B x 1 adopted from guest.
  await expect(page.getByRole('spinbutton', { name: 'Quantity for Widget' })).toHaveValue('5')
  await expect(page.getByText('Gadget')).toBeVisible()

  // The guest cart for this store was cleared after the successful merge.
  const stored = await page.evaluate((storeId) => localStorage.getItem(`ai_commerce.cart.${storeId}`), STORE_ID)
  expect(JSON.parse(stored as string).items).toEqual([])
})

test('an empty guest cart at login time does not call merge — the authenticated cart just loads normally', async ({
  page,
}) => {
  await mockAuthenticatedCartApi(page, [hydrate(VARIANT_A.id, 1)])
  await mockCustomerAuth(page)

  let mergeCalled = false
  await page.route('**/api/cart/merge', async (route) => {
    mergeCalled = true
    await route.fallback()
  })

  await login(page)
  await page.goto(`/store/${STORE_ID}/cart`)

  await expect(page.getByText('Widget')).toBeVisible()
  expect(mergeCalled).toBe(false)
})

// 6. A merge failure preserves the guest cart untouched and surfaces a
// recoverable error — authentication itself is not affected.
test('a merge failure preserves the guest cart and surfaces a recoverable error without logging the customer out', async ({
  page,
}) => {
  await mockAuthenticatedCartApi(page, [])
  await mockCustomerAuth(page)
  await page.route('**/api/cart/merge', (route) =>
    route.fulfill({ status: 500, json: { message: 'Something went wrong combining your cart.' } }),
  )

  await page.goto(`/store/${STORE_ID}`)
  await page.evaluate(
    ({ storeId, items }) => localStorage.setItem(`ai_commerce.cart.${storeId}`, JSON.stringify({ schemaVersion: 1, storeId, items })),
    {
      storeId: STORE_ID,
      items: [{ productId: VARIANT_A.product_id, variantId: VARIANT_A.id, productName: 'Widget', variantLabel: '', sku: 'W-1', displayPrice: '12.50', quantity: 2, inStockAtAdd: true }],
    },
  )

  await login(page)

  // Still authenticated — the merge failure must never roll back auth.
  await expect(page.getByRole('link', { name: 'Jane' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible()

  // Client-side navigation (not page.goto, which would hard-reload and
  // discard the in-memory mergeError this same SPA session already set)
  // — a real customer would click through, not reload the browser.
  await page.getByRole('link', { name: /^Cart/ }).click()
  await expect(page.getByRole('alert')).toContainText('Something went wrong combining your cart.')

  // The guest cart was NOT cleared.
  const stored = await page.evaluate((storeId) => localStorage.getItem(`ai_commerce.cart.${storeId}`), STORE_ID)
  expect(JSON.parse(stored as string).items).toHaveLength(1)
})

// 7. Logout does not delete the authenticated (Redis) cart — it just stops
// being what the browser reads from; a fresh guest cart takes over.
test('logout does not delete the authenticated cart, and the browser returns to (empty) guest mode', async ({ page }) => {
  let deleteCalled = false
  await mockAuthenticatedCartApi(page, [hydrate(VARIANT_A.id, 4)])
  await mockCustomerAuth(page)
  await page.route('**/api/cart', async (route) => {
    if (route.request().method() === 'DELETE') deleteCalled = true
    await route.fallback()
  })

  await login(page)
  await page.goto(`/store/${STORE_ID}/cart`)
  await expect(page.getByText('Widget')).toBeVisible()

  await page.getByRole('button', { name: 'Log out' }).click()

  expect(deleteCalled).toBe(false)

  // Post-logout, the storefront is back in guest/localStorage mode — a
  // fresh, independent (empty) cart, not a copy of the authenticated one.
  await page.goto(`/store/${STORE_ID}/cart`)
  await expect(page.getByText('Your cart is empty.')).toBeVisible()
  await expect(page.getByText('Widget')).toHaveCount(0)
})

// 8. If the authenticated cart API fails, the app surfaces an error — it
// never silently falls back to reading/writing localStorage instead.
test('authenticated cart does not silently fall back to localStorage when the API fails', async ({ page }) => {
  await mockCustomerAuth(page)
  await page.route('**/api/cart', (route) => route.fulfill({ status: 500, json: { message: 'Internal error.' } }))

  await login(page)
  await page.goto(`/store/${STORE_ID}/cart`)

  await expect(page.getByRole('alert')).toContainText('temporarily unavailable')

  // No localStorage cart was created/used as a fallback for this store.
  const stored = await page.evaluate((storeId) => localStorage.getItem(`ai_commerce.cart.${storeId}`), STORE_ID)
  expect(stored).toBeNull()
})

// 9. Store isolation for the authenticated cart — a second store's
// customer never sees the first store's items, mirroring cart.spec.ts's
// guest-mode equivalent (test E) for the authenticated path.
test('an authenticated cart is isolated per store', async ({ page }) => {
  const OTHER_STORE_ID = 901
  const otherCustomer = { ...CUSTOMER, id: 2, store_id: OTHER_STORE_ID }

  await mockAuthenticatedCartApi(page, [hydrate(VARIANT_A.id, 1)])
  await mockCustomerAuth(page)
  await login(page)
  await page.goto(`/store/${STORE_ID}/cart`)
  await expect(page.getByText('Widget')).toBeVisible()

  // A second store's customer, logged in separately (its own per-store
  // token key — see CustomerAuthContext), gets an entirely independent,
  // empty authenticated cart.
  await page.route('**/api/customers/auth/login', (route) =>
    route.fulfill({ json: { token: 'fake-other-token', customer: otherCustomer } }),
  )
  await page.route('**/api/customers/auth/me', (route) =>
    route.fulfill({ json: { customer: otherCustomer, store: { id: OTHER_STORE_ID, name: 'Other Store' } } }),
  )
  await page.route('**/api/cart', async (route) => {
    if (route.request().url().includes(String(OTHER_STORE_ID))) return route.continue()
    await route.fulfill({ json: { data: { items: [], subtotal: '0.00', currency: 'usd' } } })
  })

  await page.goto(`/store/${OTHER_STORE_ID}/login`)
  await page.getByLabel('Email').fill(otherCustomer.email)
  await page.getByLabel('Password').fill('password123')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(new RegExp(`/store/${OTHER_STORE_ID}$`))

  await page.goto(`/store/${OTHER_STORE_ID}/cart`)
  await expect(page.getByText('Your cart is empty.')).toBeVisible()
  await expect(page.getByText('Widget')).toHaveCount(0)
})

// 10. Checkout works with the async authenticated cart — full coverage
// already lives in checkout.spec.ts (Block 8D, updated for this revision);
// this is a light confirmation that isLoading is respected end-to-end.
test('checkout waits for the authenticated cart to load before rendering the form', async ({ page }) => {
  await mockAuthenticatedCartApi(page, [hydrate(VARIANT_A.id, 1)])
  await mockCustomerAuth(page)
  await login(page)

  await page.goto(`/store/${STORE_ID}/checkout`)

  await expect(page.getByRole('heading', { name: 'Checkout' })).toBeVisible()
  await expect(page.getByText('Widget × 1')).toBeVisible()
})
