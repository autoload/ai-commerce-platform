import { Link, Outlet } from 'react-router-dom'
import { useMerchantAuth } from '../auth/MerchantAuthContext'

// Deliberately minimal chrome for the /merchant/stores/* subtree only — not
// a full sidebar shell like AdminLayout. /merchant itself stays the plain
// identity landing page it already was; this wraps just the pages that
// need to navigate between each other.
export function MerchantLayout() {
  const { organization, logout } = useMerchantAuth()

  return (
    <div className="min-h-svh bg-slate-50 dark:bg-slate-950">
      <header className="flex h-14 items-center justify-between border-b border-slate-200 bg-white px-4 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-center gap-4">
          <Link to="/merchant" className="text-sm font-semibold text-slate-900 dark:text-slate-50">
            {organization?.name ?? 'AI Commerce Platform'}
          </Link>
          <Link
            to="/merchant/stores"
            className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
          >
            Stores
          </Link>
        </div>

        <button
          type="button"
          onClick={logout}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
        >
          Log out
        </button>
      </header>

      <main className="mx-auto max-w-3xl p-6">
        <Outlet />
      </main>
    </div>
  )
}
