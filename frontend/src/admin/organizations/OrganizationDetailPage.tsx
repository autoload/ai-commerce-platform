import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../services/apiClient'
import type { OrganizationStatus } from '../../services/organizationsApi'
import {
  useApproveOrganization,
  useOrganization,
  useReactivateOrganization,
  useRejectOrganization,
  useSuspendOrganization,
} from './useOrganizations'

const STATUS_LABELS: Record<OrganizationStatus, string> = {
  pending: 'Pending',
  active: 'Active',
  rejected: 'Rejected',
  suspended: 'Suspended',
}

export function OrganizationDetailPage() {
  const params = useParams<{ organizationId: string }>()
  const organizationId = Number(params.organizationId)

  const { data, isLoading, isError, error } = useOrganization(organizationId)
  const approve = useApproveOrganization(organizationId)
  const reject = useRejectOrganization(organizationId)
  const suspend = useSuspendOrganization(organizationId)
  const reactivate = useReactivateOrganization(organizationId)

  const [rejectReason, setRejectReason] = useState('')
  const [suspendReason, setSuspendReason] = useState('')
  const [showRejectForm, setShowRejectForm] = useState(false)
  const [showSuspendForm, setShowSuspendForm] = useState(false)
  const [validationError, setValidationError] = useState<string | null>(null)

  if (isLoading) {
    return <p className="text-sm text-slate-500 dark:text-slate-400">Loading organization…</p>
  }

  if (isError || !data) {
    const status = error instanceof ApiError ? error.status : null
    const message =
      status === 404
        ? 'Organization not found.'
        : error instanceof ApiError
          ? error.message
          : 'Unable to load this organization.'

    return (
      <div className="space-y-4">
        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
          {message}
        </p>
        <Link
          to="/admin/organizations"
          className="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
        >
          ← Back to organizations
        </Link>
      </div>
    )
  }

  const organization = data.data
  const actionError =
    approve.error instanceof ApiError
      ? approve.error.message
      : reject.error instanceof ApiError
        ? reject.error.message
        : suspend.error instanceof ApiError
          ? suspend.error.message
          : reactivate.error instanceof ApiError
            ? reactivate.error.message
            : null
  const errorMessage = validationError ?? actionError
  const isMutating = approve.isPending || reject.isPending || suspend.isPending || reactivate.isPending

  async function handleApprove() {
    setValidationError(null)
    try {
      await approve.mutateAsync()
    } catch {
      // Surfaced via actionError above.
    }
  }

  async function handleReject() {
    setValidationError(null)
    if (!rejectReason.trim()) {
      setValidationError('A reason is required to reject an organization.')
      return
    }
    try {
      await reject.mutateAsync(rejectReason.trim())
      setShowRejectForm(false)
      setRejectReason('')
    } catch {
      // Surfaced via actionError above.
    }
  }

  async function handleSuspend() {
    setValidationError(null)
    if (!suspendReason.trim()) {
      setValidationError('A reason is required to suspend an organization.')
      return
    }
    try {
      await suspend.mutateAsync(suspendReason.trim())
      setShowSuspendForm(false)
      setSuspendReason('')
    } catch {
      // Surfaced via actionError above.
    }
  }

  async function handleReactivate() {
    setValidationError(null)
    try {
      await reactivate.mutateAsync()
    } catch {
      // Surfaced via actionError above.
    }
  }

  return (
    <div className="space-y-6">
      <Link
        to="/admin/organizations"
        className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
      >
        ← Back to organizations
      </Link>

      <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-start justify-between">
          <div>
            <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-50">{organization.name}</h1>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{organization.slug}</p>
          </div>
          <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
            {STATUS_LABELS[organization.status] ?? organization.status}
          </span>
        </div>

        <dl className="mt-4 space-y-1 text-sm">
          {organization.status_reason && (
            <div className="flex gap-2">
              <dt className="text-slate-500 dark:text-slate-400">Reason</dt>
              <dd className="text-slate-900 dark:text-slate-100">{organization.status_reason}</dd>
            </div>
          )}
          <div className="flex gap-2">
            <dt className="text-slate-500 dark:text-slate-400">Registered</dt>
            <dd className="text-slate-900 dark:text-slate-100">
              {organization.created_at ? new Date(organization.created_at).toLocaleString() : '—'}
            </dd>
          </div>
          {organization.approved_at && (
            <div className="flex gap-2">
              <dt className="text-slate-500 dark:text-slate-400">Approved</dt>
              <dd className="text-slate-900 dark:text-slate-100">
                {new Date(organization.approved_at).toLocaleString()}
              </dd>
            </div>
          )}
          {organization.rejected_at && (
            <div className="flex gap-2">
              <dt className="text-slate-500 dark:text-slate-400">Rejected</dt>
              <dd className="text-slate-900 dark:text-slate-100">
                {new Date(organization.rejected_at).toLocaleString()}
              </dd>
            </div>
          )}
          {organization.suspended_at && (
            <div className="flex gap-2">
              <dt className="text-slate-500 dark:text-slate-400">Suspended</dt>
              <dd className="text-slate-900 dark:text-slate-100">
                {new Date(organization.suspended_at).toLocaleString()}
              </dd>
            </div>
          )}
        </dl>

        {/* Only the actions valid from the organization's CURRENT status are
            ever rendered — pending: Approve/Reject; active: Suspend;
            suspended: Reactivate; rejected: nothing (no re-application
            workflow in Phase 9A). The backend's OrganizationLifecycleService
            /OrganizationLifecycleTransitions whitelist remains fully
            authoritative regardless of what's shown here. */}
        <div className="mt-6 space-y-4 border-t border-slate-200 pt-4 dark:border-slate-800">
          {organization.status === 'pending' && !showRejectForm && (
            <div className="flex items-center gap-3">
              <button
                type="button"
                onClick={handleApprove}
                disabled={isMutating}
                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {approve.isPending ? 'Approving…' : 'Approve'}
              </button>
              <button
                type="button"
                onClick={() => setShowRejectForm(true)}
                disabled={isMutating}
                className="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950"
              >
                Reject
              </button>
            </div>
          )}

          {organization.status === 'pending' && showRejectForm && (
            <div className="max-w-sm space-y-3">
              <label htmlFor="reject-reason" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                Reason for rejection
              </label>
              <textarea
                id="reject-reason"
                rows={3}
                value={rejectReason}
                onChange={(event) => setRejectReason(event.target.value)}
                className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              />
              <div className="flex items-center gap-3">
                <button
                  type="button"
                  onClick={handleReject}
                  disabled={isMutating}
                  className="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {reject.isPending ? 'Rejecting…' : 'Confirm reject'}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setShowRejectForm(false)
                    setRejectReason('')
                    setValidationError(null)
                  }}
                  disabled={isMutating}
                  className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
                >
                  Cancel
                </button>
              </div>
            </div>
          )}

          {organization.status === 'active' && !showSuspendForm && (
            <button
              type="button"
              onClick={() => setShowSuspendForm(true)}
              disabled={isMutating}
              className="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950"
            >
              Suspend
            </button>
          )}

          {organization.status === 'active' && showSuspendForm && (
            <div className="max-w-sm space-y-3">
              <label htmlFor="suspend-reason" className="block text-sm font-medium text-slate-700 dark:text-slate-300">
                Reason for suspension
              </label>
              <textarea
                id="suspend-reason"
                rows={3}
                value={suspendReason}
                onChange={(event) => setSuspendReason(event.target.value)}
                className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              />
              <div className="flex items-center gap-3">
                <button
                  type="button"
                  onClick={handleSuspend}
                  disabled={isMutating}
                  className="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {suspend.isPending ? 'Suspending…' : 'Confirm suspend'}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setShowSuspendForm(false)
                    setSuspendReason('')
                    setValidationError(null)
                  }}
                  disabled={isMutating}
                  className="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
                >
                  Cancel
                </button>
              </div>
            </div>
          )}

          {organization.status === 'suspended' && (
            <button
              type="button"
              onClick={handleReactivate}
              disabled={isMutating}
              className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {reactivate.isPending ? 'Reactivating…' : 'Reactivate'}
            </button>
          )}

          {organization.status === 'rejected' && (
            <p className="text-sm text-slate-500 dark:text-slate-400">
              This organization was rejected. No further action is available in Phase 9A.
            </p>
          )}
        </div>

        {errorMessage && (
          <p role="alert" className="mt-3 text-sm text-red-600 dark:text-red-400">
            {errorMessage}
          </p>
        )}
      </div>
    </div>
  )
}
