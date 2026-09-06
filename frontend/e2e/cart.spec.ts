import { expect, type Page } from '@playwright/test'
import { test } from '@playwright/test'

// Block 8B E2E coverage for the client-side, localStorage-backed cart. As
// with catalog.spec.ts (Block 8A) and customer-auth.spec.ts (Block 8C),
// there is no seed/fixture data available, so the catalog API is mocked —
// deterministic and backend-independent. The cart itself is never mocked:
// these tests exercise the real CartContext/localStorage code path in a
// real browser. Checkout/Stripe remain out of scope for this block.

const STORE_A = 600
const STORE_B = 601

const PRODUCT_A = {
  id: 182,
  name: 'Test Widget',
  slug: 'test-widget',
  description: 'A widget for testing the cart.',
  category: null,
  images: [],
  options: [],
  variants: [
    { id: 152, sku: 'WIDGET-1', price: '12.50', compare_at_price: null, in_stock: true, options: [] },
  ],
}

const PRODUCT_B = {
  id: 282,
  name: 'Other Store Widget',
  slug: 'other-store-widget',
  description: null,
  category: null,
  images: [],
  options: [],
  variants: [
    { id: 252, sku: 'OTHER-1', price: '5.00', compare_at_price: null, in_stock: true, options: [] },
  ],
}

async function mockDetail(page: Page, storeId: number, product: unknown) {
  await page.route(new RegExp(`/api/shop/stores/${storeId}/products/\\d+$`), (route) =>
    route.fulfill({ json: { data: product } }),
  )
}

async function addProductAToCart(page: Page) {
  await mockDetail(page, STORE_A, PRODUCT_A)
  await page.goto(`/store/${STORE_A}/products/${PRODUCT_A.id}`)
  await page.getByRole('button', { name: 'Add to cart' }).click()
  await expect(page.getByRole('status')).toBeVisible()
}

test('A. adding a product from its detail page shows it in the cart', async ({ page }) => {
  await addProductAToCart(page)

  await page.getByRole('link', { name: /^Cart/ }).click()

  await expect(page).toHaveURL(new RegExp(`/store/${STORE_A}/cart$`))
  await expect(page.getByText('Test Widget')).toBeVisible()
  await expect(page.getByText('SKU WIDGET-1')).toBeVisible()
  await expect(page.getByRole('listitem').getByText('$12.50', { exact: true })).toBeVisible()
})

test('B. quantity controls update the line and the subtotal', async ({ page }) => {
  await addProductAToCart(page)
  await page.goto(`/store/${STORE_A}/cart`)

  await page.getByRole('button', { name: 'Increase quantity for Test Widget' }).click()
  await expect(page.getByRole('spinbutton', { name: 'Quantity for Test Widget' })).toHaveValue('2')
  await expect(page.getByRole('listitem').getByText('$25.00')).toBeVisible() // line total: 2 x 12.50
  await expect(page.getByTestId('cart-subtotal')).toHaveText('$25.00')

  await page.getByRole('button', { name: 'Decrease quantity for Test Widget' }).click()
  await expect(page.getByRole('spinbutton', { name: 'Quantity for Test Widget' })).toHaveValue('1')
  await expect(page.getByRole('listitem').getByText('$12.50', { exact: true })).toBeVisible()
  await expect(page.getByTestId('cart-subtotal')).toHaveText('$12.50')

  // Quantity never drops below 1 — the decrement button disables itself at the floor.
  await expect(page.getByRole('button', { name: 'Decrease quantity for Test Widget' })).toBeDisabled()
})

test('C. removing an item empties the cart', async ({ page }) => {
  await addProductAToCart(page)
  await page.goto(`/store/${STORE_A}/cart`)

  await page.getByRole('button', { name: 'Remove' }).click()

  await expect(page.getByText('Your cart is empty.')).toBeVisible()
})

test('D. cart contents persist across a page reload', async ({ page }) => {
  await addProductAToCart(page)
  await page.goto(`/store/${STORE_A}/cart`)
  await expect(page.getByText('Test Widget')).toBeVisible()

  await page.reload()

  await expect(page.getByText('Test Widget')).toBeVisible()
  await expect(page.getByRole('listitem').getByText('$12.50', { exact: true })).toBeVisible()
})

test('E. a cart built for one store is not visible on another store', async ({ page }) => {
  await addProductAToCart(page)
  await page.goto(`/store/${STORE_A}/cart`)
  await expect(page.getByText('Test Widget')).toBeVisible()

  await mockDetail(page, STORE_B, PRODUCT_B)
  await page.goto(`/store/${STORE_B}/cart`)

  await expect(page.getByText('Your cart is empty.')).toBeVisible()
  await expect(page.getByText('Test Widget')).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Cart', exact: true })).toBeVisible()

  // Store A's cart is untouched by visiting store B.
  await page.goto(`/store/${STORE_A}/cart`)
  await expect(page.getByText('Test Widget')).toBeVisible()
})
