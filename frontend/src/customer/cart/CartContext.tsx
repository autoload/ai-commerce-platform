import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import { useParams } from 'react-router-dom'
import { readCart, writeCart, type CartItem } from './cartStorage'

export type AddCartItemInput = Omit<CartItem, 'quantity'>

type CartContextValue = {
  items: CartItem[]
  itemCount: number
  subtotal: number
  addItem: (item: AddCartItemInput, quantity?: number) => void
  incrementItem: (variantId: number) => void
  decrementItem: (variantId: number) => void
  setItemQuantity: (variantId: number, quantity: number) => void
  removeItem: (variantId: number) => void
}

const CartContext = createContext<CartContextValue | null>(null)

// Store-scoped, localStorage-backed, shared by guest and authenticated
// customers alike — see cartStorage.ts's module docblock for the full
// rationale. Logging in/out never touches this cart at all (no
// server-side merge, per the approved Block 8B scope), so it can never mix
// carts between stores or get corrupted by an auth-state transition.
export function CartProvider({ children }: { children: ReactNode }) {
  const params = useParams<{ storeId: string }>()
  const storeId = Number(params.storeId)

  // { storeId, items } instead of a bare items array so navigating between
  // two different stores' storefronts (without a full remount) is detected
  // and re-read during render — the same "adjust state when a key changes"
  // pattern used in CustomerAuthContext, rather than a useEffect that would
  // commit one render with the previous store's cart before correcting.
  const [state, setState] = useState(() => ({ storeId, items: readCart(storeId) }))

  let items = state.items
  if (state.storeId !== storeId) {
    items = readCart(storeId)
    setState({ storeId, items })
  }

  const persist = useCallback(
    (next: CartItem[]) => {
      writeCart(storeId, next)
      setState({ storeId, items: next })
    },
    [storeId],
  )

  const addItem = useCallback(
    (item: AddCartItemInput, quantity = 1) => {
      const safeQuantity = Number.isInteger(quantity) && quantity >= 1 ? quantity : 1
      const existingIndex = items.findIndex((line) => line.variantId === item.variantId)

      if (existingIndex === -1) {
        persist([...items, { ...item, quantity: safeQuantity }])
        return
      }

      const next = [...items]
      next[existingIndex] = { ...next[existingIndex], quantity: next[existingIndex].quantity + safeQuantity }
      persist(next)
    },
    [items, persist],
  )

  const incrementItem = useCallback(
    (variantId: number) => {
      persist(items.map((line) => (line.variantId === variantId ? { ...line, quantity: line.quantity + 1 } : line)))
    },
    [items, persist],
  )

  const decrementItem = useCallback(
    (variantId: number) => {
      // Never below 1 — removing a line is a distinct, explicit action.
      persist(
        items.map((line) =>
          line.variantId === variantId ? { ...line, quantity: Math.max(1, line.quantity - 1) } : line,
        ),
      )
    },
    [items, persist],
  )

  const setItemQuantity = useCallback(
    (variantId: number, quantity: number) => {
      if (!Number.isInteger(quantity) || quantity < 1) {
        return
      }
      persist(items.map((line) => (line.variantId === variantId ? { ...line, quantity } : line)))
    },
    [items, persist],
  )

  const removeItem = useCallback(
    (variantId: number) => {
      persist(items.filter((line) => line.variantId !== variantId))
    },
    [items, persist],
  )

  const itemCount = useMemo(() => items.reduce((sum, line) => sum + line.quantity, 0), [items])

  // Display-only subtotal computed from the stored price snapshot — never
  // authoritative. See cartStorage.ts's module docblock.
  const subtotal = useMemo(
    () => items.reduce((sum, line) => sum + Number(line.displayPrice) * line.quantity, 0),
    [items],
  )

  const value: CartContextValue = {
    items,
    itemCount,
    subtotal,
    addItem,
    incrementItem,
    decrementItem,
    setItemQuantity,
    removeItem,
  }

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>
}

export function useCart(): CartContextValue {
  const ctx = useContext(CartContext)
  if (!ctx) {
    throw new Error('useCart must be used within a CartProvider')
  }
  return ctx
}
