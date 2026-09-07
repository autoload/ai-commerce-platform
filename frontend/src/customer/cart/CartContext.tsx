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

  // ---- Login-time merge tracking. Declared here — before the
  // authenticated cart query below — rather than alongside the merge
  // effect further down, because the query's own `enabled` flag must
  // already reflect merge state on the very first render of a login
  // transition.
  //
  // hasBeenUnauthenticated is read during render (guestCartPendingMerge
  // below needs it) and so must be real state — a ref can change without
  // ever causing the re-render that would make that change visible to
  // render-time logic, which is exactly the kind of gap that would
  // silently reopen the race this block exists to close. It's corrected
  // during render — the same pattern guestState above already uses for a
  // storeId change — by comparing the current `status` against the last
  // one this provider observed, rather than via a useEffect: detecting
  // "status just changed" is exactly the kind of derivation React's own
  // guidance says belongs in render, not an effect (an effect existed
  // here in an earlier draft of this fix and was replaced after linting
  // flagged the setState-in-effect it required).
  //
  // hasBeenUnauthenticated only becomes true once this provider has
  // actually observed a logged-out state during this mount (distinguishing
  // a genuine login from a session-restore, which never arms it) — never
  // reset, a one-way latch for the mount's lifetime.
  //
  // hasMergedForThisLoginRef stays a plain ref (not state): it is read
  // only inside the merge effect below, never during render, so it needs
  // no re-render of its own — guards against a second merge *attempt* for
  // this login, reset to false on a failed attempt specifically so a
  // future retry can re-attempt the network call. (A genuine retry isn't
  // wired to anything yet — see the merge failure handling below — so
  // this reset is currently forward-looking bookkeeping, matching the
  // comment already there before this fix.)
  //
  // mergeFailed exists specifically to close a race this project's
  // Phase 8F integration testing found: the authenticated GET /api/cart
  // below and the merge's own POST /api/cart/merge used to fire
  // concurrently on login with no ordering guarantee — if the GET's
  // response happened to resolve after the merge's, it would silently
  // overwrite the correctly-merged cart with stale pre-merge data. See
  // guestCartPendingMerge/suppressAuthCartFetch below for the fix. Set
  // only from the merge's own .catch() (an async continuation, not a
  // synchronous effect-body call) — the success path needs no equivalent
  // flag, since a successful merge already clears the guest cart itself,
  // which is what actually lifts guestCartPendingMerge's gate below.
  const [mergeArmingState, setMergeArmingState] = useState(() => ({
    lastStatus: status,
    hasBeenUnauthenticated: status === 'unauthenticated',
  }))
  const hasMergedForThisLoginRef = useRef(false)
  const [mergeFailed, setMergeFailed] = useState(false)
  const [mergeError, setMergeError] = useState<string | null>(null)

  if (mergeArmingState.lastStatus !== status) {
    setMergeArmingState({
      lastStatus: status,
      hasBeenUnauthenticated: mergeArmingState.hasBeenUnauthenticated || status === 'unauthenticated',
    })
    if (status === 'unauthenticated') {
      setMergeFailed(false)
    }
  }

  // hasMergedForThisLoginRef's reset lives in its own tiny effect, not the
  // render-time correction above — refs must never be written during
  // render (only reads/writes inside effects or event handlers are safe),
  // and since this is a plain ref mutation (not a setState call), an
  // effect for it doesn't trigger the "derive during render instead"
  // concern that setState-in-effect calls do.
  useEffect(() => {
    if (status === 'unauthenticated') {
      hasMergedForThisLoginRef.current = false
    }
  }, [status])

  const hasBeenUnauthenticated = mergeArmingState.hasBeenUnauthenticated

  // True from the first render where a merge is about to be needed through
  // to the moment it actually resolves. Covers the whole async gap purely
  // from data already available during render (no separate "in flight"
  // flag needed): a non-empty guest cart keeps this true from before the
  // merge starts until it succeeds (at which point the cart is cleared,
  // naturally flipping this false) or fails (at which point mergeFailed
  // explicitly overrides it, since a failed attempt deliberately leaves
  // the guest cart un-cleared — see mergeFailed's own docblock above).
  const guestCartPendingMerge =
    isAuthenticated && hasBeenUnauthenticated && !mergeFailed && readCart(storeId).length > 0
  const suppressAuthCartFetch = guestCartPendingMerge

  // ---- Authenticated (Redis-backed) state.
  const cartQueryKey = useMemo(() => ['cart', storeId, 'authenticated'] as const, [storeId])

  const authCartQuery = useQuery({
    queryKey: cartQueryKey,
    queryFn: () => cartApi.getCart(token as string),
    enabled: isAuthenticated && token !== null && !suppressAuthCartFetch,
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
  // left alone). hasBeenUnauthenticated/hasMergedForThisLoginRef/
  // mergeFailed/mergeError are all declared above, alongside the
  // authenticated cart query they now gate; the transition-detection that
  // used to live in a separate effect here now happens during render (see
  // mergeArmingState above).
  //
  // hasMergedForThisLoginRef is read as a plain ref, deliberately NOT
  // listed as an effect dependency — it is set by this very effect, and
  // (being a ref) changing it never itself triggers a dependency-array
  // comparison the way state would. hasBeenUnauthenticated (real state) is
  // listed below; when it flips true, isAuthenticated is still false at
  // that exact moment (the flip happens on becoming unauthenticated), so
  // the extra re-run this causes bails out immediately on the first guard.
  //
  // Deliberately no synchronous setState call anywhere in this effect's
  // own body (only inside the .then()/.catch() continuations below) — the
  // race-prevention gate (guestCartPendingMerge above) is derived entirely
  // from data already available at render time (the guest cart's own
  // contents, plus mergeFailed once a failure actually occurs), so there
  // is nothing this effect needs to flip synchronously up front.
  useEffect(() => {
    if (!isAuthenticated || !token) return
    if (!hasBeenUnauthenticated) return // session restore, not a fresh login
    if (hasMergedForThisLoginRef.current) return
    hasMergedForThisLoginRef.current = true

    const cartToMerge = readCart(storeId)
    if (cartToMerge.length === 0) {
      // Point 2 of the approved design: nothing to merge — the
      // authenticated cart query above loads normally on its own
      // (guestCartPendingMerge is already false in this case, since it
      // requires a non-empty guest cart).
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
        // succeeded — never before. This is also what lifts
        // guestCartPendingMerge's gate on success (readCart(storeId) then
        // returns empty) — no separate "merge succeeded" flag needed.
        writeCart(storeId, [])
        setGuestState({ storeId, items: [] })
        setMergeError(null)
      })
      .catch((error: unknown) => {
        // Merge failed: do NOT clear localStorage (nothing lost), and do
        // not touch authentication state at all — the customer is still
        // logged in. Surface a recoverable message; the merge can be
        // retried (e.g. next time this provider re-mounts a fresh
        // attempt, or via a future manual retry action) via
        // hasMergedForThisLoginRef resetting below. Setting mergeFailed
        // lifts guestCartPendingMerge's gate despite the guest cart still
        // being non-empty, so the authenticated cart loads normally
        // despite the failed merge, matching this provider's original
        // behavior, rather than staying blocked indefinitely.
        hasMergedForThisLoginRef.current = false
        setMergeFailed(true)
        setMergeError(
          describeApiError(
            error,
            'We could not combine your saved cart with your account. Your items are still saved on this device — please try again from your cart.',
          ),
        )
      })
  }, [isAuthenticated, token, storeId, setAuthCartData, hasBeenUnauthenticated])

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

  // Includes suppressAuthCartFetch: while the GET is deliberately held back
  // pending a merge, React Query's own isLoading reports false (a disabled
  // query with no data yet isn't "loading" by its definition) — without
  // this, CartPage/CheckoutPage would briefly flash an "empty cart" state
  // during the merge window instead of the loading state they showed here
  // before this fix (when the GET fired immediately and was itself what
  // isLoading tracked).
  const isLoading = isAuthenticated && (authCartQuery.isLoading || suppressAuthCartFetch)

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
