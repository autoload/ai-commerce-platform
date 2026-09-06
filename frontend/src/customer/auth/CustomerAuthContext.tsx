import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import {
  fetchCurrentCustomer,
  loginCustomer,
  logoutCustomer,
  registerCustomer,
  type Customer,
  type CustomerStore,
} from '../../services/customerAuth'

// Customer is a structurally separate identity domain from PlatformAdmin
// and Merchant — its own storage, its own React Query key namespace, its
// own context. Do not merge this with admin/auth/AuthContext or
// merchant/auth/MerchantAuthContext.
//
// Unlike those two (one global identity per browser), a customer account is
// permanently bound to a single store (customers.email is unique per store,
// not globally — see CustomerRegisterRequest), and one browser could
// plausibly hold sessions for two different stores' storefronts. The token
// is therefore stored under a per-store key, and this provider reads
// storeId from the route itself rather than taking it as a prop.
type CustomerAuthStatus = 'loading' | 'authenticated' | 'unauthenticated'

type CustomerRegisterInput = {
  name: string
  email: string
  password: string
  password_confirmation: string
}

type CustomerAuthContextValue = {
  status: CustomerAuthStatus
  storeId: number
  token: string | null
  customer: Customer | null
  store: CustomerStore | null
  login: (email: string, password: string) => Promise<void>
  isLoggingIn: boolean
  loginError: string | null
  register: (payload: CustomerRegisterInput) => Promise<void>
  isRegistering: boolean
  registerError: string | null
  logout: () => void
}

const CustomerAuthContext = createContext<CustomerAuthContextValue | null>(null)

function describeError(error: unknown): string | null {
  if (!(error instanceof ApiError)) {
    return null
  }
  if (error.errors) {
    const fieldMessages = Object.values(error.errors).flat()
    if (fieldMessages.length > 0) {
      return fieldMessages.join(' ')
    }
  }
  return error.message
}

function tokenStorageKey(storeId: number): string {
  return `ai_commerce.customer_token.${storeId}`
}

export function CustomerAuthProvider({ children }: { children: ReactNode }) {
  const params = useParams<{ storeId: string }>()
  const storeId = Number(params.storeId)
  const storageKey = tokenStorageKey(storeId)
  const queryClient = useQueryClient()

  // { key, token } instead of a bare token so a storeId change (navigating
  // to a different store's storefront in the same browser) can be detected
  // and re-read during render itself — see React's "adjusting state when a
  // prop changes" pattern — rather than via a useEffect, which would commit
  // one render with the previous store's token before correcting itself.
  const [tokenState, setTokenState] = useState(() => ({
    key: storageKey,
    token: localStorage.getItem(storageKey),
  }))

  let token = tokenState.token
  if (tokenState.key !== storageKey) {
    token = localStorage.getItem(storageKey)
    setTokenState({ key: storageKey, token })
  }

  const setToken = useCallback(
    (next: string | null) => setTokenState({ key: storageKey, token: next }),
    [storageKey],
  )

  const clearToken = useCallback(() => {
    localStorage.removeItem(storageKey)
    setToken(null)
  }, [storageKey, setToken])

  const meQuery = useQuery({
    queryKey: ['customer', storeId, 'me', token],
    queryFn: () => fetchCurrentCustomer(token as string),
    enabled: token !== null,
    retry: false,
  })

  const loginMutation = useMutation({
    mutationFn: ({ email, password }: { email: string; password: string }) => loginCustomer(storeId, email, password),
    onSuccess: (data) => {
      localStorage.setItem(storageKey, data.token)
      // Unlike merchant login, this response carries no `store` object to
      // prime the /me cache with (see CustomerAuthResponse) — the enabled
      // meQuery above refetches on its own once `token` changes below.
      setToken(data.token)
    },
  })

  const registerMutation = useMutation({
    mutationFn: (payload: CustomerRegisterInput) => registerCustomer({ ...payload, store_id: storeId }),
    onSuccess: (data) => {
      localStorage.setItem(storageKey, data.token)
      setToken(data.token)
    },
  })

  const logout = useCallback(() => {
    if (token) {
      // Best-effort server-side revocation — local state is cleared
      // regardless, since the token may already be invalid.
      void logoutCustomer(token).catch(() => {})
    }
    clearToken()
    queryClient.removeQueries({ queryKey: ['customer', storeId] })
  }, [token, clearToken, queryClient, storeId])

  // A stored token the API no longer accepts (revoked/expired) is simply
  // treated as unauthenticated — logging in again overwrites it.
  const status: CustomerAuthStatus =
    token === null || meQuery.isError ? 'unauthenticated' : meQuery.data ? 'authenticated' : 'loading'

  const value: CustomerAuthContextValue = {
    status,
    storeId,
    token,
    customer: meQuery.data?.customer ?? null,
    store: meQuery.data?.store ?? null,
    login: async (email, password) => {
      await loginMutation.mutateAsync({ email, password })
    },
    isLoggingIn: loginMutation.isPending,
    loginError: describeError(loginMutation.error),
    register: async (payload) => {
      await registerMutation.mutateAsync(payload)
    },
    isRegistering: registerMutation.isPending,
    registerError: describeError(registerMutation.error),
    logout,
  }

  return <CustomerAuthContext.Provider value={value}>{children}</CustomerAuthContext.Provider>
}

export function useCustomerAuth(): CustomerAuthContextValue {
  const ctx = useContext(CustomerAuthContext)
  if (!ctx) {
    throw new Error('useCustomerAuth must be used within a CustomerAuthProvider')
  }
  return ctx
}
