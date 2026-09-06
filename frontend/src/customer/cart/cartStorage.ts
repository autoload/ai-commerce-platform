// Client-side-only cart storage — no /api/cart, no Redis, no backend
// persistence (Database Design 2.2 / CLAUDE.md's approved MVP simplification
// for Phase 8: both guest and authenticated customers share one
// localStorage-backed cart per store; see project-status.md's Block 8B
// entry for the recorded deviation from the originally-documented
// Redis-backed authenticated cart).
//
// IMPORTANT: everything stored here is DISPLAY-ONLY. `displayPrice` and
// `inStockAtAdd` are snapshots taken at add-to-cart time for rendering the
// cart UI — checkout (Block 8D) must independently re-fetch and revalidate
// product existence, variant existence, current price, and live inventory
// from the backend before charging anything. Nothing in this module is
// ever treated as authoritative.

export const CART_SCHEMA_VERSION = 1

export type CartItem = {
  productId: number
  variantId: number
  productName: string
  // Human-readable option summary, e.g. "Color: Red, Size: M" — empty
  // string for a product with no option matrix (the common case today).
  variantLabel: string
  sku: string
  // Decimal string snapshot of the variant's price at add-to-cart time —
  // display-only, never authoritative. See module docblock.
  displayPrice: string
  quantity: number
  // The catalog's `in_stock` signal (Block 8A) captured at add-to-cart
  // time — a display hint only, used to flag a line as possibly stale.
  // Never re-derives real-time availability; checkout re-validates for real.
  inStockAtAdd: boolean
}

type StoredCart = {
  schemaVersion: number
  storeId: number
  items: CartItem[]
}

export function cartStorageKey(storeId: number): string {
  return `ai_commerce.cart.${storeId}`
}

function isValidCartItem(value: unknown): value is CartItem {
  if (typeof value !== 'object' || value === null) {
    return false
  }
  const candidate = value as Record<string, unknown>

  return (
    typeof candidate.productId === 'number' &&
    typeof candidate.variantId === 'number' &&
    typeof candidate.productName === 'string' &&
    typeof candidate.variantLabel === 'string' &&
    typeof candidate.sku === 'string' &&
    typeof candidate.displayPrice === 'string' &&
    typeof candidate.quantity === 'number' &&
    Number.isInteger(candidate.quantity) &&
    candidate.quantity >= 1 &&
    typeof candidate.inStockAtAdd === 'boolean'
  )
}

/**
 * Reads and validates the cart for one store. Anything that doesn't match
 * the current schema — corrupt JSON, a missing/mismatched schemaVersion, a
 * storeId that doesn't match the key it was read from, a non-array
 * `items`, or an individual malformed line — is dropped rather than
 * allowed to crash the storefront. A future schema migration (bumping
 * CART_SCHEMA_VERSION) would branch on the stored version here.
 */
export function readCart(storeId: number): CartItem[] {
  let raw: string | null
  try {
    raw = localStorage.getItem(cartStorageKey(storeId))
  } catch {
    return []
  }
  if (!raw) {
    return []
  }

  let parsed: unknown
  try {
    parsed = JSON.parse(raw)
  } catch {
    return []
  }

  if (typeof parsed !== 'object' || parsed === null) {
    return []
  }

  const candidate = parsed as Partial<StoredCart>
  if (candidate.schemaVersion !== CART_SCHEMA_VERSION) {
    return []
  }
  if (candidate.storeId !== storeId) {
    return []
  }
  if (!Array.isArray(candidate.items)) {
    return []
  }

  return candidate.items.filter(isValidCartItem)
}

export function writeCart(storeId: number, items: CartItem[]): void {
  const payload: StoredCart = { schemaVersion: CART_SCHEMA_VERSION, storeId, items }
  try {
    localStorage.setItem(cartStorageKey(storeId), JSON.stringify(payload))
  } catch {
    // Storage full/unavailable (private browsing, quota exceeded) — the
    // cart simply won't persist this change; nothing to crash over.
  }
}
