import { apiRequest } from './apiClient'

export type OrganizationStatus = 'pending' | 'active' | 'rejected' | 'suspended'

export type Organization = {
  id: number
  name: string
  slug: string
  status: OrganizationStatus
  status_reason: string | null
  approved_at: string | null
  approved_by_platform_admin_id: number | null
  rejected_at: string | null
  rejected_by_platform_admin_id: number | null
  suspended_at: string | null
  suspended_by_platform_admin_id: number | null
  created_at: string | null
  updated_at: string | null
}

type OrganizationListMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

type OrganizationListResponse = {
  data: Organization[]
  meta: OrganizationListMeta
}

type OrganizationResponse = {
  data: Organization
}

export type OrganizationListParams = {
  status?: OrganizationStatus
  page?: number
}

export function listOrganizations(token: string, params: OrganizationListParams = {}) {
  const query = new URLSearchParams()
  if (params.status) query.set('status', params.status)
  if (params.page) query.set('page', String(params.page))
  const qs = query.toString()

  return apiRequest<OrganizationListResponse>(`/api/platform/organizations${qs ? `?${qs}` : ''}`, { token })
}

export function getOrganization(token: string, organizationId: number) {
  return apiRequest<OrganizationResponse>(`/api/platform/organizations/${organizationId}`, { token })
}

export function approveOrganization(token: string, organizationId: number) {
  return apiRequest<OrganizationResponse>(`/api/platform/organizations/${organizationId}/approve`, {
    method: 'POST',
    token,
  })
}

export function rejectOrganization(token: string, organizationId: number, reason: string) {
  return apiRequest<OrganizationResponse>(`/api/platform/organizations/${organizationId}/reject`, {
    method: 'POST',
    body: { reason },
    token,
  })
}

export function suspendOrganization(token: string, organizationId: number, reason: string) {
  return apiRequest<OrganizationResponse>(`/api/platform/organizations/${organizationId}/suspend`, {
    method: 'POST',
    body: { reason },
    token,
  })
}

export function reactivateOrganization(token: string, organizationId: number) {
  return apiRequest<OrganizationResponse>(`/api/platform/organizations/${organizationId}/reactivate`, {
    method: 'POST',
    token,
  })
}
