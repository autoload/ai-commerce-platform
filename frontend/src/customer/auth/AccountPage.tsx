import { Card } from '../../components/Card'
import { useCustomerAuth } from './CustomerAuthContext'

// Minimal authenticated-only page — exists in Block 8C to give the customer
// auth boundary a real protected route to guard. Order history (Block 8E)
// and any richer account management are separate, later scope.
export function AccountPage() {
  const { customer, store } = useCustomerAuth()

  return (
    <div className="space-y-6">
      <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">Your Account</h1>

      <Card>
        <dl className="space-y-3 text-sm">
          <div className="flex gap-2">
            <dt className="text-slate-500 dark:text-slate-400">Name</dt>
            <dd className="text-slate-900 dark:text-slate-100">{customer?.name}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="text-slate-500 dark:text-slate-400">Email</dt>
            <dd className="text-slate-900 dark:text-slate-100">{customer?.email}</dd>
          </div>
          <div className="flex gap-2">
            <dt className="text-slate-500 dark:text-slate-400">Store</dt>
            <dd className="text-slate-900 dark:text-slate-100">{store?.name}</dd>
          </div>
        </dl>
      </Card>
    </div>
  )
}
