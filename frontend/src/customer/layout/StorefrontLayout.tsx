import { Link, Outlet, useParams } from 'react-router-dom'
import { useCustomerAuth } from '../auth/CustomerAuthContext'

// Deliberately minimal chrome, mirroring MerchantLayout's approach for its
// own subtree. No cart badge yet — that lands with Block 8B, not before.
// No public "get store" endpoint exists to fetch a display name from for an
// unauthenticated visitor, so the header falls back to "Store #{id}" until
// an authenticated /me response supplies the real name.
export function StorefrontLayout() {
  const params = useParams<{ storeId: string }>()
  const storeId = params.storeId
  const { status, customer, store, logout } = useCustomerAuth()

  return (
    <div className="min-h-svh bg-slate-50 dark:bg-slate-950">
      <header className="flex h-14 items-center justify-between border-b border-slate-200 bg-white px-4 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-center gap-4">
          <Link to={`/store/${storeId}`} className="text-sm font-semibold text-slate-900 dark:text-slate-50">
            {store?.name ?? `Store #${storeId}`}
          </Link>
          <Link
            to={`/store/${storeId}/products`}
            className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
          >
            Products
          </Link>
        </div>

        <div className="flex items-center gap-4">
          {status === 'authenticated' && (
            <>
              <Link
                to={`/store/${storeId}/account`}
                className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
              >
                {customer?.name ?? 'Account'}
              </Link>
              <button
                type="button"
                onClick={logout}
                className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
              >
                Log out
              </button>
            </>
          )}

          {status === 'unauthenticated' && (
            <>
              <Link
                to={`/store/${storeId}/login`}
                className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
              >
                Sign in
              </Link>
              <Link
                to={`/store/${storeId}/register`}
                className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-500"
              >
                Register
              </Link>
            </>
          )}
        </div>
      </header>

      <main className="mx-auto max-w-5xl p-6">
        <Outlet />
      </main>
    </div>
  )
}
