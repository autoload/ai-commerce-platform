import { NavLink } from 'react-router-dom'
import { ADMIN_NAV_ITEMS } from './navigation'

const linkClasses = ({ isActive }: { isActive: boolean }) =>
  [
    'block rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap transition',
    isActive
      ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
      : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100',
  ].join(' ')

export function Sidebar() {
  return (
    <>
      {/* Desktop: fixed left column. */}
      <nav
        aria-label="Admin navigation"
        className="hidden w-56 shrink-0 border-r border-slate-200 bg-white p-3 md:block dark:border-slate-800 dark:bg-slate-900"
      >
        <ul className="space-y-1">
          {ADMIN_NAV_ITEMS.map((item) => (
            <li key={item.path}>
              <NavLink to={item.path} className={linkClasses}>
                {item.label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>

      {/* Mobile: horizontal scrollable strip under the header. */}
      <nav
        aria-label="Admin navigation"
        className="flex gap-1 overflow-x-auto border-b border-slate-200 bg-white p-2 md:hidden dark:border-slate-800 dark:bg-slate-900"
      >
        {ADMIN_NAV_ITEMS.map((item) => (
          <NavLink key={item.path} to={item.path} className={linkClasses}>
            {item.label}
          </NavLink>
        ))}
      </nav>
    </>
  )
}
