type QuantityStepperProps = {
  value: number
  onIncrement: () => void
  onDecrement: () => void
  onChange?: (value: number) => void
  min?: number
  // Disambiguates aria-labels when multiple steppers appear on one page
  // (e.g. one per cart line).
  label?: string
}

export function QuantityStepper({ value, onIncrement, onDecrement, onChange, min = 1, label }: QuantityStepperProps) {
  const suffix = label ? ` for ${label}` : ''

  return (
    <div className="inline-flex items-center gap-2">
      <button
        type="button"
        onClick={onDecrement}
        disabled={value <= min}
        aria-label={`Decrease quantity${suffix}`}
        className="flex h-7 w-7 items-center justify-center rounded-md border border-slate-300 text-sm font-medium text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
      >
        −
      </button>

      {onChange ? (
        <input
          type="number"
          inputMode="numeric"
          min={min}
          value={value}
          aria-label={`Quantity${suffix}`}
          onChange={(event) => {
            const next = Number(event.target.value)
            if (Number.isInteger(next) && next >= min) {
              onChange(next)
            }
          }}
          className="w-14 rounded-md border border-slate-300 px-2 py-1 text-center text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
        />
      ) : (
        <span className="w-6 text-center text-sm text-slate-900 dark:text-slate-100">{value}</span>
      )}

      <button
        type="button"
        onClick={onIncrement}
        aria-label={`Increase quantity${suffix}`}
        className="flex h-7 w-7 items-center justify-center rounded-md border border-slate-300 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
      >
        +
      </button>
    </div>
  )
}
