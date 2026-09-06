import { apiRequest } from './apiClient'

// Customer is a third, structurally separate identity domain from
// PlatformAdmin (platformAuth.ts) and Merchant (merchantAuth.ts) — do not
// merge these into one shared auth surface. Unlike those two,
// customers.email is unique only per store (not globally), so every
// register/login call is store-scoped by store_id — see
// CustomerAuthController/CustomerRegisterRequest/CustomerLoginRequest.
export type Customer = {
  id: number
  name: string
  email: string
  phone: string | null
  store_id: number
}

export type CustomerStore = {
  id: number
  name: string
}

// login/register intentionally return only {token, customer} — no store
// object (unlike /me) — see CustomerAuthController::register()/login().
export type CustomerAuthResponse = {
  token: string
  customer: Customer
}

export type CustomerMeResponse = {
  customer: Customer
  store: CustomerStore
}

export type CustomerRegisterPayload = {
  store_id: number
  name: string
  email: string
  password: string
  password_confirmation: string
}

export function registerCustomer(payload: CustomerRegisterPayload) {
  return apiRequest<CustomerAuthResponse>('/api/customers/auth/register', {
    method: 'POST',
    body: payload,
  })
}

export function loginCustomer(storeId: number, email: string, password: string) {
  return apiRequest<CustomerAuthResponse>('/api/customers/auth/login', {
    method: 'POST',
    body: { store_id: storeId, email, password },
  })
}

export function logoutCustomer(token: string) {
  return apiRequest<{ message: string }>('/api/customers/auth/logout', {
    method: 'POST',
    token,
  })
}

export function fetchCurrentCustomer(token: string) {
  return apiRequest<CustomerMeResponse>('/api/customers/auth/me', { token })
}
