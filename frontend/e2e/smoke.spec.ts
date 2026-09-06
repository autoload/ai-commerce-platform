import { expect, test } from '@playwright/test'

// Verifies the Playwright setup itself (dev server boot, baseURL, browser
// launch) rather than any Phase 8 customer-facing flow, which does not
// exist yet. Targets the pre-existing Platform Admin login redirect since
// it requires no backend/API call: AuthContext only queries `/me` when a
// token is present in localStorage (see admin/auth/AuthContext.tsx), so an
// unauthenticated visit resolves to `unauthenticated` and redirects
// immediately, with no dependency on the Docker stack being up.
test('unauthenticated visitor is redirected to the admin login page', async ({ page }) => {
  await page.goto('/')

  await expect(page).toHaveURL(/\/admin\/login$/)
  await expect(page.getByRole('heading', { name: 'Platform Admin' })).toBeVisible()
  await expect(page.getByLabel('Email')).toBeVisible()
  await expect(page.getByLabel('Password')).toBeVisible()
})
