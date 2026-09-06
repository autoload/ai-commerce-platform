import { Link, useParams } from 'react-router-dom'

export function HomePage() {
  const params = useParams<{ storeId: string }>()

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-semibold text-slate-900 dark:text-slate-50">Welcome</h1>
      <p className="text-sm text-slate-500 dark:text-slate-400">Browse this store's catalog to get started.</p>
      <Link
        to={`/store/${params.storeId}/products`}
        className="inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-500"
      >
        Browse products
      </Link>
    </div>
  )
}
