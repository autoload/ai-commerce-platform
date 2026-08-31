import { Link } from 'react-router-dom'
import { Card } from '../components/Card'
import { useMerchantAuth } from './auth/MerchantAuthContext'

// Deliberately minimal — this is NOT a business dashboard. It exists only to
// prove the authenticated session works end-to-end (identity + org + role +
// logout). Organizations/Stores/Products/Orders/Analytics/etc. belong to
// later feature blocks, not this one.
const ROLE_LABELS: Record<string, string> = {
  owner: 'Owner',
  store_admin: 'Store Admin',
  staff: 'Staff',
}

export function MerchantLandingPage() {
  const { user, organization, role, logout } = useMerchantAuth()

  return (
    <div className="flex min-h-svh items-center justify-center bg-slate-50 px-4 dark:bg-slate-950">
      <div className="w-full max-w-md">
        <Card>
          <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">
            Welcome{user ? `, ${user.name}` : ''}
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">You are signed in.</p>

          <dl className="mt-6 space-y-3 text-sm">
            <div className="flex justify-between gap-4">
              <dt className="text-slate-500 dark:text-slate-400">Email</dt>
              <dd className="text-right text-slate-900 dark:text-slate-100">{user?.email ?? '—'}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-slate-500 dark:text-slate-400">Organization</dt>
              <dd className="text-right text-slate-900 dark:text-slate-100">{organization?.name ?? '—'}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-slate-500 dark:text-slate-400">Organization status</dt>
              <dd className="text-right text-slate-900 dark:text-slate-100">{organization?.status ?? '—'}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-slate-500 dark:text-slate-400">Role</dt>
              <dd className="text-right text-slate-900 dark:text-slate-100">
                {role ? (ROLE_LABELS[role] ?? role) : '—'}
              </dd>
            </div>
          </dl>

          <Link
            to="/merchant/stores"
            className="mt-6 block w-full rounded-md bg-indigo-600 px-3 py-2 text-center text-sm font-medium text-white transition hover:bg-indigo-500"
          >
            Manage stores
          </Link>

          <button
            type="button"
            onClick={logout}
            className="mt-3 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
          >
            Log out
          </button>
        </Card>
      </div>
    </div>
  )
}
