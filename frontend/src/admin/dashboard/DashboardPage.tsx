import { Card } from '../../components/Card'
import { useAuth } from '../auth/AuthContext'

// Placeholder metrics only — no analytics/data wiring yet (that's a later
// feature block). Every value is explicitly "—" so it never reads as real.
const PLACEHOLDER_METRICS = [
  { label: 'Organizations' },
  { label: 'Stores' },
  { label: 'Customers' },
  { label: 'Orders' },
]

export function DashboardPage() {
  const { admin } = useAuth()

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900 dark:text-slate-50">Dashboard</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          {admin ? `Welcome back, ${admin.name}.` : 'Welcome back.'}
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {PLACEHOLDER_METRICS.map((metric) => (
          <Card key={metric.label}>
            <p className="text-sm font-medium text-slate-500 dark:text-slate-400">{metric.label}</p>
            <p className="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-50">—</p>
            <p className="mt-1 text-xs text-slate-400 dark:text-slate-500">No data yet</p>
          </Card>
        ))}
      </div>
    </div>
  )
}
