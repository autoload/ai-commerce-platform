import { Link, Outlet, useParams } from 'react-router-dom'

// Deliberately minimal chrome, mirroring MerchantLayout's approach for its
// own subtree. No cart badge or account/login link yet — those land with
// Block 8B (cart) and Block 8C (customer auth), not before. No public
// "get store" endpoint exists to fetch a display name from, so the header
// identifies the store only by id for now.
export function StorefrontLayout() {
  const params = useParams<{ storeId: string }>()
  const storeId = params.storeId

  return (
    <div className="min-h-svh bg-slate-50 dark:bg-slate-950">
      <header className="flex h-14 items-center justify-between border-b border-slate-200 bg-white px-4 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-center gap-4">
          <Link to={`/store/${storeId}`} className="text-sm font-semibold text-slate-900 dark:text-slate-50">
            Store #{storeId}
          </Link>
          <Link
            to={`/store/${storeId}/products`}
            className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
          >
            Products
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-5xl p-6">
        <Outlet />
      </main>
    </div>
  )
}
