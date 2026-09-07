import { expect, type Page } from '@playwright/test'
import { test } from '@playwright/test'

// Block 8C E2E coverage for customer authentication. As with catalog.spec.ts
// (Block 8A), there is no seed/fixture data or public store-lookup endpoint
// available, so these tests mock the customer-auth network responses
// directly rather than depending on a live database — deterministic and
// backend-independent, matching Block 8A's approach. Cart/checkout flows
// remain out of scope for this block.

const STORE_ID = 500

const LOGIN_URL = '**/api/customers/auth/login'
const LOGOUT_URL = '**/api/customers/auth/logout'
const ME_URL = '**/api/customers/auth/me'

const SAMPLE_CUSTOMER = {
  id: 1,
  name: 'Jane Doe',
  email: 'jane@example.com',
  phone: null,
  store_id: STORE_ID,
}

const SAMPLE_STORE = { id: STORE_ID, name: 'Test Store' }

const CART_URL = '**/api/cart'

async function mockAuthenticatedSession(page: Page) {
  await page.route(LOGIN_URL, (route) =>
    route.fulfill({ json: { token: 'fake-customer-token', customer: SAMPLE_CUSTOMER } }),
  )
  await page.route(ME_URL, (route) => route.fulfill({ json: { customer: SAMPLE_CUSTOMER, store: SAMPLE_STORE } }))
  await page.route(LOGOUT_URL, (route) => route.fulfill({ json: { message: 'Logged out.' } }))
  // Phase 8B revision: CartContext now fetches the authenticated cart as
  // soon as login resolves. Mocked here (always empty — cart behavior
  // itself is authenticated-cart.spec.ts's concern) purely so this file's
  // login/logout assertions stay deterministic and backend-independent,
  // matching its own header comment's stated approach.
  await page.route(CART_URL, (route) => route.fulfill({ json: { data: { items: [], subtotal: '0.00', currency: 'usd' } } }))
}

async function login(page: Page) {
  await page.goto(`/store/${STORE_ID}/login`)
  await page.getByLabel('Email').fill(SAMPLE_CUSTOMER.email)
  await page.getByLabel('Password').fill('password123')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}$`))
}

test('unauthenticated visitor sees sign-in/register links and can reach the login page', async ({ page }) => {
  await page.goto(`/store/${STORE_ID}`)

  await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Register' })).toBeVisible()

  await page.getByRole('link', { name: 'Sign in' }).click()

  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/login$`))
  await expect(page.getByRole('heading', { name: 'Sign In' })).toBeVisible()
})

test('a protected customer route redirects an unauthenticated visitor to login', async ({ page }) => {
  await page.goto(`/store/${STORE_ID}/account`)

  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/login$`))
})

test('customer can log in, navigation reflects the authenticated state, and can reach the account page', async ({
  page,
}) => {
  await mockAuthenticatedSession(page)

  await login(page)

  await expect(page.getByRole('link', { name: 'Jane Doe' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Sign in' })).toHaveCount(0)

  await page.getByRole('link', { name: 'Jane Doe' }).click()

  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/account$`))
  await expect(page.getByText('jane@example.com')).toBeVisible()
  await expect(page.getByRole('main').getByText('Test Store')).toBeVisible()
})

test('logging out returns the storefront to the unauthenticated state', async ({ page }) => {
  await mockAuthenticatedSession(page)

  await login(page)
  await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible()

  await page.getByRole('button', { name: 'Log out' }).click()

  await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Register' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Log out' })).toHaveCount(0)

  // The protected route must no longer be reachable after logout.
  await page.goto(`/store/${STORE_ID}/account`)
  await expect(page).toHaveURL(new RegExp(`/store/${STORE_ID}/login$`))
})
