import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '../../services/apiClient'
import * as cartApi from '../../services/cartApi'
import type { CartApiItem, CartApiState } from '../../services/cartApi'
import { useCustomerAuth } from '../auth/CustomerAuthContext'
import { readCart, writeCart, type CartItem } from './cartStorage'

export type AddCartItemInput = Omit<CartItem, 'quantity'>

type CartContextValue = {
  items: CartItem[]
  itemCount: number
  subtotal: number
  // Meaningful only in authenticated mode (the initial GET /api/cart fetch,
  // or a merge still in flight) — always false for the synchronous guest
  // cart. Consumers that don't care can safely ignore it.
  isLoading: boolean
  // A non-blocking, surfaced-but-recoverable problem: a login-time merge
  // failure, or the authenticated cart being temporarily unreachable. Never
  // set for the guest cart. Never causes a silent fallback to localStorage.
  error: string | null
  addItem: (item: AddCartItemInput, quantity?: number) => void
  incrementItem: (variantId: number) => void
  decrementItem: (variantId: number) => void
  setItemQuantity: (variantId: number, quantity: number) => void
  removeItem: (variantId: number) => void
  // Block 8D: called only after checkout reaches a confirmed-successful
  // payment state — never on validation failure, payment failure, or
  // merely submitting the checkout request. See CheckoutForm.tsx. Async in
  // authenticated mode (DELETE /api/cart); the local/displayed cart is
  // cleared immediately regardless of whether that network call succeeds,
  // since payment has already been confirmed by this point — clearing is
  // bookkeeping, not a correctness gate. Never called by CartContext
  // itself for any other reason (not on checkout submit, not on a
  // PaymentIntent "processing" status) — see CheckoutForm.tsx's own status
  // handling, unchanged by this revision.
  clearCart: () => Promise<void>
}

const CartContext = createContext<CartContextValue | null>(null)

function mapApiItem(item: CartApiItem): CartItem {
  return {
    productId: item.product_id,
    variantId: item.product_variant_id,
    productName: item.product_name,
    variantLabel: item.options.map((option) => `${option.option}: ${option.value}`).join(', '),
    sku: item.sku,
    displayPrice: item.price,
    quantity: item.quantity,
    // Unlike the guest cart's inStockAtAdd (an add-to-cart-time snapshot),
    // this is re-derived from MySQL on every authenticated read — always
    // current, never stale. Reusing the same CartItem field keeps CartPage
    // unmodified; the two modes' values just have different freshness
    // guarantees, both equally non-authoritative at checkout regardless.
    inStockAtAdd: item.in_stock,
  }
}

function describeApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError && error.message) {
    return error.message
  }

  return fallback
}

// Store-scoped. Guest mode (unauthenticated/loading) is unchanged from the
// original Block 8B implementation: synchronous, localStorage-backed,
// shared by nobody else. Authenticated mode is backed by the Phase 8B
// revision's /api/cart* endpoints (Redis via CartService) — React
// Query-managed, mirroring CustomerAuthContext's own useQuery/useMutation
// pattern. Logging in triggers a one-time merge of the guest cart into the
// authenticated one (see the effect below); logging out does NOT delete
// the authenticated (Redis) cart — it simply stops being what this
// provider reads from, and a fresh guest cart (likely empty, since it was
// cleared by the prior successful merge) takes over.
export function CartProvider({ children }: { children: ReactNode }) {
  const params = useParams<{ storeId: string }>()
  const storeId = Number(params.storeId)
  const { status, token } = useCustomerAuth()
  const queryClient = useQueryClient()

  const isAuthenticated = status === 'authenticated'

  // ---- Guest (localStorage) state — unchanged from the original
  // implementation. Always maintained, even while authenticated, so it's
  // exactly where the merge effect below expects to find it, and so
  // logging out has a cart to fall back to with no extra bookkeeping.
  const [guestState, setGuestState] = useState(() => ({ storeId, items: readCart(storeId) }))

  let guestItems = guestState.items
  if (guestState.storeId !== storeId) {
    guestItems = readCart(storeId)
    setGuestState({ storeId, items: guestItems })
  }

  const persistGuest = useCallback(
    (next: CartItem[]) => {
      writeCart(storeId, next)
      setGuestState({ storeId, items: next })
    },
    [storeId],
  )

  // ---- Authenticated (Redis-backed) state.
  const cartQueryKey = useMemo(() => ['cart', storeId, 'authenticated'] as const, [storeId])

  const authCartQuery = useQuery({
    queryKey: cartQueryKey,
    queryFn: () => cartApi.getCart(token as string),
    enabled: isAuthenticated && token !== null,
    retry: false,
  })

  const setAuthCartData = useCallback(
    (state: CartApiState) => queryClient.setQueryData(cartQueryKey, { data: state }),
    [queryClient, cartQueryKey],
  )

  const addMutation = useMutation({
    mutationFn: ({ variantId, quantity }: { variantId: number; quantity: number }) =>
      cartApi.addCartItem(token as string, variantId, quantity),
    onSuccess: (response) => setAuthCartData(response.data),
  })

  const updateMutation = useMutation({
    mutationFn: ({ variantId, quantity }: { variantId: number; quantity: number }) =>
      cartApi.updateCartItem(token as string, variantId, quantity),
    onSuccess: (response) => setAuthCartData(response.data),
  })

  const removeMutation = useMutation({
    mutationFn: (variantId: number) => cartApi.removeCartItem(token as string, variantId),
    onSuccess: (response) => setAuthCartData(response.data),
  })

  // ---- Login-time merge: guest localStorage cart -> POST /api/cart/merge
  // -> authenticated Redis cart. Triggered exactly once per genuine
  // login/registration event within this mount — NOT on session restore
  // (an already-valid token resolving straight to 'authenticated' on first
  // load never passes through 'unauthenticated' first, so it's correctly
  // left alone). hasBeenUnauthenticatedRef is what distinguishes the two:
  // it only becomes true once this provider has actually observed a
  // logged-out state during this mount.
  const hasBeenUnauthenticatedRef = useRef(false)
  const hasMergedForThisLoginRef = useRef(false)
  const [mergeError, setMergeError] = useState<string | null>(null)

  useEffect(() => {
    if (status === 'unauthenticated') {
      hasBeenUnauthenticatedRef.current = true
      hasMergedForThisLoginRef.current = false
    }
  }, [status])

  useEffect(() => {
    if (!isAuthenticated || !token) return
    if (!hasBeenUnauthenticatedRef.current) return // session restore, not a fresh login
    if (hasMergedForThisLoginRef.current) return
    hasMergedForThisLoginRef.current = true

    const cartToMerge = readCart(storeId)
    if (cartToMerge.length === 0) {
      // Point 2 of the approved design: nothing to merge — the
      // authenticated cart query above loads normally on its own.
      return
    }

    cartApi
      .mergeCart(
        token,
        cartToMerge.map((item) => ({ product_variant_id: item.variantId, quantity: item.quantity })),
      )
      .then((response) => {
        setAuthCartData(response.data)
        // Only clear the guest cart after the merge has actually
        // succeeded — never before.
        writeCart(storeId, [])
        setGuestState({ storeId, items: [] })
        setMergeError(null)
      })
      .catch((error: unknown) => {
        // Merge failed: do NOT clear localStorage (nothing lost), and do
        // not touch authentication state at all — the customer is still
        // logged in. Surface a recoverable message; the merge can be
        // retried (e.g. next time this provider re-mounts a fresh
        // attempt, or via a future manual retry action).
        hasMergedForThisLoginRef.current = false
        setMergeError(
          describeApiError(
            error,
            'We could not combine your saved cart with your account. Your items are still saved on this device — please try again from your cart.',
          ),
        )
      })
  }, [isAuthenticated, token, storeId, setAuthCartData])

  // ---- Public actions — branch by mode, same call signatures either way.
  const addItem = useCallback(
    (item: AddCartItemInput, quantity = 1) => {
      const safeQuantity = Number.isInteger(quantity) && quantity >= 1 ? quantity : 1

      if (isAuthenticated) {
        if (!token) return
        addMutation.mutate({ variantId: item.variantId, quantity: safeQuantity })
        return
      }

      const existingIndex = guestItems.findIndex((line) => line.variantId === item.variantId)
      if (existingIndex === -1) {
        persistGuest([...guestItems, { ...item, quantity: safeQuantity }])
        return
      }
      const next = [...guestItems]
      next[existingIndex] = { ...next[existingIndex], quantity: next[existingIndex].quantity + safeQuantity }
      persistGuest(next)
    },
    [isAuthenticated, token, addMutation, guestItems, persistGuest],
  )

  const currentQuantity = useCallback(
    (variantId: number): number => {
      if (isAuthenticated) {
        const line = authCartQuery.data?.data.items.find((i) => i.product_variant_id === variantId)
        return line?.quantity ?? 0
      }
      return guestItems.find((line) => line.variantId === variantId)?.quantity ?? 0
    },
    [isAuthenticated, authCartQuery.data, guestItems],
  )

  const setItemQuantity = useCallback(
    (variantId: number, quantity: number) => {
      if (!Number.isInteger(quantity) || quantity < 1) return

      if (isAuthenticated) {
        if (!token) return
        updateMutation.mutate({ variantId, quantity })
        return
      }

      persistGuest(guestItems.map((line) => (line.variantId === variantId ? { ...line, quantity } : line)))
    },
    [isAuthenticated, token, updateMutation, guestItems, persistGuest],
  )

  const incrementItem = useCallback(
    (variantId: number) => setItemQuantity(variantId, currentQuantity(variantId) + 1),
    [setItemQuantity, currentQuantity],
  )

  const decrementItem = useCallback(
    (variantId: number) => setItemQuantity(variantId, Math.max(1, currentQuantity(variantId) - 1)),
    [setItemQuantity, currentQuantity],
  )

  const removeItem = useCallback(
    (variantId: number) => {
      if (isAuthenticated) {
        if (!token) return
        removeMutation.mutate(variantId)
        return
      }
      persistGuest(guestItems.filter((line) => line.variantId !== variantId))
    },
    [isAuthenticated, token, removeMutation, guestItems, persistGuest],
  )

  const clearCart = useCallback(async () => {
    if (isAuthenticated) {
      // Payment is already confirmed by the time CheckoutForm calls this
      // (see the type's own docblock) — clear what's displayed
      // immediately, then best-effort tell the server. A failed DELETE
      // must never leave a confirmed-paid-for cart looking un-cleared.
      setAuthCartData({ items: [], subtotal: '0.00', currency: 'usd' })
      if (token) {
        try {
          await cartApi.clearCart(token)
        } catch {
          // Best-effort — already optimistically cleared above.
        }
      }
      return
    }

    persistGuest([])
  }, [isAuthenticated, token, setAuthCartData, persistGuest])

  // ---- Unified view exposed to consumers — CartPage/ProductDetailPage/
  // StorefrontLayout/QuantityStepper never need to know which mode is active.
  const items = useMemo(
    () => (isAuthenticated ? (authCartQuery.data?.data.items ?? []).map(mapApiItem) : guestItems),
    [isAuthenticated, authCartQuery.data, guestItems],
  )

  const itemCount = useMemo(() => items.reduce((sum, line) => sum + line.quantity, 0), [items])

  // Display-only, computed client-side from whichever items array is
  // active — never authoritative either way. See cartStorage.ts.
  const subtotal = useMemo(
    () => items.reduce((sum, line) => sum + Number(line.displayPrice) * line.quantity, 0),
    [items],
  )

  const isLoading = isAuthenticated && authCartQuery.isLoading

  const error =
    mergeError ??
    (isAuthenticated && authCartQuery.isError
      ? 'Your cart is temporarily unavailable. Please try again shortly.'
      : null)

  const value: CartContextValue = {
    items,
    itemCount,
    subtotal,
    isLoading,
    error,
    addItem,
    incrementItem,
    decrementItem,
    setItemQuantity,
    removeItem,
    clearCart,
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
