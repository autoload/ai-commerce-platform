export function FullPageSpinner() {
  return (
    <div className="flex min-h-svh items-center justify-center bg-slate-50 dark:bg-slate-950">
      <div
        role="status"
        aria-label="Loading"
        className="h-8 w-8 animate-spin rounded-full border-2 border-slate-300 border-t-indigo-600 dark:border-slate-700 dark:border-t-indigo-400"
      />
    </div>
  )
}
