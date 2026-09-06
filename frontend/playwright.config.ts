import { defineConfig, devices } from '@playwright/test'

// Phase 8 Block 8-0: minimal E2E scaffolding. Only Chromium is configured
// for now — additional browser projects can be added once real storefront
// flows exist to justify the extra run time.
//
// The dev server this points at only talks to the Laravel API over HTTP
// (see vite.config.ts) — it never touches MySQL/Redis directly, so no
// backend/Docker stack is required for the smoke test in e2e/smoke.spec.ts.
// Future flow tests (cart, checkout, order history) will need the full
// Docker stack running and seeded data; that requirement will be documented
// alongside those tests, not here.
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: 'html',
  use: {
    baseURL: 'http://localhost:5173',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: {
    command: 'npm run dev',
    url: 'http://localhost:5173',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
})
