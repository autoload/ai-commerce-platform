import { expect, type Page } from '@playwright/test'
import { test } from '@playwright/test'

// Block 8A E2E coverage for the public storefront catalog flow. The public
// catalog API (GET /api/shop/stores/{store}/products[/{product}]) has no
// seed/fixture data available in this environment or in CI, so these tests
// mock the network responses directly (matching the exact
// CatalogProductResource shape verified against the live backend during
// implementation) rather than depending on a live database. This keeps the
// suite deterministic and independent of backend/database state, consistent
// with the smoke test in smoke.spec.ts. Cart/checkout flows are explicitly
// out of scope for this block.

const LIST_URL = /\/api\/shop\/stores\/\d+\/products(\?.*)?$/
const DETAIL_URL = /\/api\/shop\/stores\/\d+\/products\/\d+$/

const SAMPLE_PRODUCT = {
  id: 182,
  name: 'Test Widget',
  slug: 'test-widget',
  description: 'A widget for testing the storefront catalog.',
  category: { id: 5, name: 'Widgets', slug: 'widgets' },
  images: [],
  options: [],
  variants: [
    {
      id: 152,
      sku: 'WIDGET-1',
      price: '12.50',
      compare_at_price: null,
      in_stock: true,
      options: [],
    },
  ],
}

function mockList(page: Page, products: unknown[]) {
  return page.route(LIST_URL, (route) =>
    route.fulfill({
      json: {
        data: products,
        meta: { current_page: 1, last_page: 1, per_page: 15, total: products.length },
      },
    }),
  )
}

function mockDetail(page: Page, product: unknown) {
  return page.route(DETAIL_URL, (route) => route.fulfill({ json: { data: product } }))
}

test('catalog list renders products and links to a working detail page', async ({ page }) => {
  await mockList(page, [SAMPLE_PRODUCT])
  await mockDetail(page, SAMPLE_PRODUCT)

  await page.goto('/store/324/products')

  await expect(page.getByText('Test Widget')).toBeVisible()
  await expect(page.getByText('$12.50')).toBeVisible()
  await expect(page.getByText('In stock')).toBeVisible()

  await page.getByText('Test Widget').click()

  await expect(page).toHaveURL(/\/store\/324\/products\/182$/)
  await expect(page.getByRole('heading', { name: 'Test Widget' })).toBeVisible()
  await expect(page.getByText('SKU WIDGET-1')).toBeVisible()
})

test('catalog list shows an empty state when the store has no products', async ({ page }) => {
  await mockList(page, [])

  await page.goto('/store/324/products')

  await expect(page.getByText('No products are available right now.')).toBeVisible()
})

test('catalog list shows an error message when the API fails', async ({ page }) => {
  await page.route(LIST_URL, (route) => route.fulfill({ status: 500, json: { message: 'Server error.' } }))

  await page.goto('/store/324/products')

  await expect(page.getByRole('alert')).toBeVisible()
})

test('a nonexistent product detail page shows a not-found message', async ({ page }) => {
  await page.route(DETAIL_URL, (route) => route.fulfill({ status: 404, json: { message: 'Not found.' } }))

  await page.goto('/store/324/products/999999')

  await expect(page.getByRole('alert')).toHaveText('Product not found.')
  await page.getByRole('link', { name: '← Back to products' }).click()
  await expect(page).toHaveURL(/\/store\/324\/products$/)
})
