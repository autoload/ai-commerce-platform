import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '../../services/apiClient'
import {
  fetchCurrentMerchant,
  loginMerchant,
  logoutMerchant,
  registerMerchant,
  type MerchantOrganization,
  type MerchantRegisterPayload,
  type MerchantRole,
  type MerchantUser,
} from '../../services/merchantAuth'

// Merchant is a structurally separate identity domain from PlatformAdmin —
// its own storage key, its own React Query key namespace, its own context.
// Do not merge this with admin/auth/AuthContext.
const TOKEN_STORAGE_KEY = 'ai_commerce.merchant_token'

type MerchantAuthStatus = 'loading' | 'authenticated' | 'unauthenticated'

type MerchantAuthContextValue = {
  status: MerchantAuthStatus
  user: MerchantUser | null
  organization: MerchantOrganization | null
  role: MerchantRole | null
  login: (email: string, password: string) => Promise<void>
  isLoggingIn: boolean
  loginError: string | null
  register: (payload: MerchantRegisterPayload) => Promise<void>
  isRegistering: boolean
  registerError: string | null
  logout: () => void
}

const MerchantAuthContext = createContext<MerchantAuthContextValue | null>(null)

function describeError(error: unknown): string | null {
  if (!(error instanceof ApiError)) {
    return null
  }
  // Surface the specific field message (e.g. "The email has already been
  // taken.") when the API returned one, rather than the generic top-level
  // "The given data was invalid." — most actionable for register/login forms.
  if (error.errors) {
    const fieldMessages = Object.values(error.errors).flat()
    if (fieldMessages.length > 0) {
      return fieldMessages.join(' ')
    }
  }
  return error.message
}

export function MerchantAuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(() => localStorage.getItem(TOKEN_STORAGE_KEY))
  const queryClient = useQueryClient()

  const clearToken = useCallback(() => {
    localStorage.removeItem(TOKEN_STORAGE_KEY)
    setToken(null)
  }, [])

  const meQuery = useQuery({
    queryKey: ['merchant', 'me', token],
    queryFn: () => fetchCurrentMerchant(token as string),
    enabled: token !== null,
    retry: false,
  })

  // organization/role are typed nullable on the login/register response
  // (defensively, for a merchant account with no organization membership —
  // unreachable via this UI, since registration always creates one). When
  // present, prime the /me cache so there's no redundant round-trip; when
  // absent, skip priming and let the enabled `meQuery` above resolve the
  // real state itself (it will correctly fail if the account truly has no
  // membership, which is the actual source of truth).
  const primeIdentity = useCallback(
    (tokenValue: string, user: MerchantUser, organization: MerchantOrganization | null, role: MerchantRole | null) => {
      if (organization && role) {
        queryClient.setQueryData(['merchant', 'me', tokenValue], { user, organization, role })
      }
    },
    [queryClient],
  )

  const loginMutation = useMutation({
    mutationFn: ({ email, password }: { email: string; password: string }) => loginMerchant(email, password),
    onSuccess: (data) => {
      localStorage.setItem(TOKEN_STORAGE_KEY, data.token)
      primeIdentity(data.token, data.user, data.organization, data.role)
      setToken(data.token)
    },
  })

  const registerMutation = useMutation({
    mutationFn: (payload: MerchantRegisterPayload) => registerMerchant(payload),
    onSuccess: (data) => {
      localStorage.setItem(TOKEN_STORAGE_KEY, data.token)
      primeIdentity(data.token, data.user, data.organization, data.role)
      setToken(data.token)
    },
  })

  const logout = useCallback(() => {
    if (token) {
      // Best-effort server-side revocation — local state is cleared
      // regardless, since the token may already be invalid.
      void logoutMerchant(token).catch(() => {})
    }
    clearToken()
    queryClient.removeQueries({ queryKey: ['merchant'] })
  }, [token, clearToken, queryClient])

  // A stored token the API no longer accepts (revoked/expired) is simply
  // treated as unauthenticated — logging in again overwrites it.
  const status: MerchantAuthStatus =
    token === null || meQuery.isError ? 'unauthenticated' : meQuery.data ? 'authenticated' : 'loading'

  const value: MerchantAuthContextValue = {
    status,
    user: meQuery.data?.user ?? null,
    organization: meQuery.data?.organization ?? null,
    role: meQuery.data?.role ?? null,
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

  return <MerchantAuthContext.Provider value={value}>{children}</MerchantAuthContext.Provider>
}

export function useMerchantAuth(): MerchantAuthContextValue {
  const ctx = useContext(MerchantAuthContext)
  if (!ctx) {
    throw new Error('useMerchantAuth must be used within a MerchantAuthProvider')
  }
  return ctx
}
