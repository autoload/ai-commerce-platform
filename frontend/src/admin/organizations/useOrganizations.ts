import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  approveOrganization,
  getOrganization,
  listOrganizations,
  reactivateOrganization,
  rejectOrganization,
  suspendOrganization,
  type OrganizationListParams,
} from '../../services/organizationsApi'
import { useAuth } from '../auth/AuthContext'

const organizationsKey = ['organizations'] as const

export function useOrganizationsList(params: OrganizationListParams = {}) {
  const { token } = useAuth()

  return useQuery({
    queryKey: [...organizationsKey, 'list', params],
    queryFn: () => listOrganizations(token as string, params),
    enabled: token !== null,
  })
}

export function useOrganization(organizationId: number) {
  const { token } = useAuth()

  return useQuery({
    queryKey: [...organizationsKey, 'detail', organizationId],
    queryFn: () => getOrganization(token as string, organizationId),
    enabled: token !== null,
    retry: false,
  })
}

export function useApproveOrganization(organizationId: number) {
  const { token } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => approveOrganization(token as string, organizationId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: organizationsKey })
    },
  })
}

export function useRejectOrganization(organizationId: number) {
  const { token } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (reason: string) => rejectOrganization(token as string, organizationId, reason),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: organizationsKey })
    },
  })
}

export function useSuspendOrganization(organizationId: number) {
  const { token } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (reason: string) => suspendOrganization(token as string, organizationId, reason),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: organizationsKey })
    },
  })
}

export function useReactivateOrganization(organizationId: number) {
  const { token } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => reactivateOrganization(token as string, organizationId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: organizationsKey })
    },
  })
}
