import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '../../services/apiClient'
import {
  fetchCurrentPlatformAdmin,
  loginPlatformAdmin,
  logoutPlatformAdmin,
  type PlatformAdmin,
} from '../../services/platformAuth'

const TOKEN_STORAGE_KEY = 'ai_commerce.platform_admin_token'

type AuthStatus = 'loading' | 'authenticated' | 'unauthenticated'

type AuthContextValue = {
  status: AuthStatus
  admin: PlatformAdmin | null
  login: (email: string, password: string) => Promise<void>
  isLoggingIn: boolean
  loginError: string | null
  logout: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(() => localStorage.getItem(TOKEN_STORAGE_KEY))
  const queryClient = useQueryClient()

  const clearToken = useCallback(() => {
    localStorage.removeItem(TOKEN_STORAGE_KEY)
    setToken(null)
  }, [])

  const meQuery = useQuery({
    queryKey: ['platform-admin', 'me', token],
    queryFn: () => fetchCurrentPlatformAdmin(token as string),
    enabled: token !== null,
    retry: false,
  })

  const loginMutation = useMutation({
    mutationFn: ({ email, password }: { email: string; password: string }) =>
      loginPlatformAdmin(email, password),
    onSuccess: (data) => {
      localStorage.setItem(TOKEN_STORAGE_KEY, data.token)
      queryClient.setQueryData(['platform-admin', 'me', data.token], {
        platform_admin: data.platform_admin,
      })
      setToken(data.token)
    },
  })

  const logout = useCallback(() => {
    if (token) {
      // Best-effort server-side revocation — local state is cleared
      // regardless, since the token may already be invalid.
      void logoutPlatformAdmin(token).catch(() => {})
    }
    clearToken()
    queryClient.removeQueries({ queryKey: ['platform-admin'] })
  }, [token, clearToken, queryClient])

  // A stored token the API no longer accepts (revoked/expired) is simply
  // treated as unauthenticated — logging in again overwrites it. No need to
  // eagerly clear localStorage from an effect for an already-invalid value.
  const status: AuthStatus =
    token === null || meQuery.isError ? 'unauthenticated' : meQuery.data ? 'authenticated' : 'loading'

  const value: AuthContextValue = {
    status,
    admin: meQuery.data?.platform_admin ?? null,
    login: async (email, password) => {
      await loginMutation.mutateAsync({ email, password })
    },
    isLoggingIn: loginMutation.isPending,
    loginError: loginMutation.error instanceof ApiError ? loginMutation.error.message : null,
    logout,
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) {
    throw new Error('useAuth must be used within an AuthProvider')
  }
  return ctx
}
