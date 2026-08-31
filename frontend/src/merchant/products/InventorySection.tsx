import { useState, type FormEvent } from 'react'
import { ApiError } from '../../services/apiClient'
import type { InventoryAdjustReason } from '../../services/inventoryApi'
import { useAdjustInventory, useInventory } from './useInventory'

type InventorySectionProps = {
  storeId: number
  variantId: number
  canAdjust: boolean
}

// Deliberately part of the Product detail page, not a separate top-level
// Inventory browsing screen — a merchant thinks of "this product's stock,"
// not inventory as its own navigable concept, at this MVP stage.
export function InventorySection({ storeId, variantId, canAdjust }: InventorySectionProps) {
  const { data, isLoading, isError, error } = useInventory(storeId, variantId)
  const adjustInventory = useAdjustInventory(storeId, variantId)

  const [delta, setDelta] = useState('')
  const [reason, setReason] = useState<InventoryAdjustReason>('restock')
  const [note, setNote] = useState('')
  const [validationError, setValidationError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setValidationError(null)

    const deltaValue = Number(delta)
    if (!delta || Number.isNaN(deltaValue) || !Number.isInteger(deltaValue) || deltaValue === 0) {
      setValidationError('Enter a non-zero whole number.')
      return
    }

    try {
      await adjustInventory.mutateAsync({ delta: deltaValue, reason, note: note || undefined })
      setDelta('')
      setNote('')
    } catch {
      // Server-side failure message is already surfaced via mutationError below.
    }
  }

  const mutationError = adjustInventory.error instanceof ApiError ? adjustInventory.error.message : null
  const errorMessage = validationError ?? mutationError

  return (
    <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
      <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-50">Inventory</h2>

      {isLoading && <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Loading inventory…</p>}

      {isError && (
        <p role="alert" className="mt-2 text-sm text-red-600 dark:text-red-400">
          {error instanceof ApiError ? error.message : 'Unable to load inventory.'}
        </p>
      )}

      {data && (
        <p className="mt-2 text-2xl font-semibold text-slate-900 dark:text-slate-50">
          {data.data.quantity_on_hand}{' '}
          <span className="text-sm font-normal text-slate-500 dark:text-slate-400">in stock</span>
        </p>
      )}

      {canAdjust && (
        <form className="mt-4 flex flex-wrap items-end gap-3" onSubmit={handleSubmit} noValidate>
          <div>
            <label htmlFor="delta" className="block text-xs font-medium text-slate-700 dark:text-slate-300">
              Change
            </label>
            <input
              id="delta"
              name="delta"
              type="number"
              step="1"
              value={delta}
              onChange={(event) => setDelta(event.target.value)}
              placeholder="e.g. 10 or -5"
              className="mt-1 w-32 rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            />
          </div>

          <div>
            <label htmlFor="reason" className="block text-xs font-medium text-slate-700 dark:text-slate-300">
              Reason
            </label>
            <select
              id="reason"
              name="reason"
              value={reason}
              onChange={(event) => setReason(event.target.value as InventoryAdjustReason)}
              className="mt-1 rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            >
              <option value="restock">Restock</option>
              <option value="adjustment">Adjustment</option>
            </select>
          </div>

          <div className="min-w-40 flex-1">
            <label htmlFor="note" className="block text-xs font-medium text-slate-700 dark:text-slate-300">
              Note (optional)
            </label>
            <input
              id="note"
              name="note"
              type="text"
              value={note}
              onChange={(event) => setNote(event.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            />
          </div>

          <button
            type="submit"
            disabled={adjustInventory.isPending}
            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {adjustInventory.isPending ? 'Saving…' : 'Apply'}
          </button>
        </form>
      )}

      {errorMessage && (
        <p role="alert" className="mt-3 text-sm text-red-600 dark:text-red-400">
          {errorMessage}
        </p>
      )}
    </div>
  )
}
