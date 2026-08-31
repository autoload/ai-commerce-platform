import { apiRequest } from './apiClient'

// Merchant is a structurally separate identity domain from PlatformAdmin
// (see platformAuth.ts) — do not merge these into one shared auth surface.
export type MerchantUser = {
  id: number
  name: string
  email: string
}

export type MerchantOrganization = {
  id: number
  name: string
  slug: string
  status: 'pending' | 'active' | 'rejected' | 'suspended'
}

export type MerchantRole = 'owner' | 'store_admin' | 'staff'

export type MerchantRegisterPayload = {
  name: string
  email: string
  password: string
  password_confirmation: string
  organization_name: string
}

export type MerchantAuthResponse = {
  token: string
  user: MerchantUser
  organization: MerchantOrganization | null
  role: MerchantRole | null
}

export type MerchantMeResponse = {
  user: MerchantUser
  organization: MerchantOrganization
  role: MerchantRole
}

export function registerMerchant(payload: MerchantRegisterPayload) {
  return apiRequest<MerchantAuthResponse>('/api/auth/register', {
    method: 'POST',
    body: payload,
  })
}

export function loginMerchant(email: string, password: string) {
  return apiRequest<MerchantAuthResponse>('/api/auth/login', {
    method: 'POST',
    body: { email, password },
  })
}

export function logoutMerchant(token: string) {
  return apiRequest<{ message: string }>('/api/auth/logout', {
    method: 'POST',
    token,
  })
}

export function fetchCurrentMerchant(token: string) {
  return apiRequest<MerchantMeResponse>('/api/auth/me', { token })
}
