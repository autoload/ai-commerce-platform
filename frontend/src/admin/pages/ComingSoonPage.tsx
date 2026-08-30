export function ComingSoonPage({ label }: { label: string }) {
  return (
    <div className="flex min-h-[50vh] flex-col items-center justify-center text-center">
      <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">{label}</h1>
      <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
        This section hasn't been built yet — coming in a future feature block.
      </p>
    </div>
  )
}
