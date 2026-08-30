import { apiRequest } from './apiClient'

export type PlatformAdmin = {
  id: number
  name: string
  email: string
}

type LoginResponse = { token: string; platform_admin: PlatformAdmin }
type MeResponse = { platform_admin: PlatformAdmin }

export function loginPlatformAdmin(email: string, password: string) {
  return apiRequest<LoginResponse>('/api/platform/auth/login', {
    method: 'POST',
    body: { email, password },
  })
}

export function logoutPlatformAdmin(token: string) {
  return apiRequest<{ message: string }>('/api/platform/auth/logout', {
    method: 'POST',
    token,
  })
}

export function fetchCurrentPlatformAdmin(token: string) {
  return apiRequest<MeResponse>('/api/platform/auth/me', { token })
}
