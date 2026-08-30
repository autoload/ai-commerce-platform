import { useAuth } from '../auth/AuthContext'

export function Header() {
  const { admin, logout } = useAuth()

  return (
    <header className="flex h-14 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 dark:border-slate-800 dark:bg-slate-900">
      <span className="text-sm font-semibold text-slate-900 dark:text-slate-50">
        AI Commerce Platform — Back Office
      </span>

      <div className="flex items-center gap-3">
        {admin && (
          <span className="hidden text-sm text-slate-500 sm:inline dark:text-slate-400">{admin.email}</span>
        )}
        <button
          type="button"
          onClick={logout}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
        >
          Log out
        </button>
      </div>
    </header>
  )
}
