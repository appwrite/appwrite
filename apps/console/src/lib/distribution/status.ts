export type StatusVariant =
  | 'pending'
  | 'processing'
  | 'success'
  | 'error'
  | 'inactive'
  | 'warning'
  | 'info'

export type StatusAppearance = {
  label: string
  variant: StatusVariant
}

export const BUILD_STATUS: Record<string, StatusAppearance> = {
  waiting: { label: 'Waiting', variant: 'pending' },
  processing: { label: 'Processing', variant: 'processing' },
  building: { label: 'Building', variant: 'processing' },
  signing: { label: 'Signing', variant: 'processing' },
  ready: { label: 'Ready', variant: 'success' },
  failed: { label: 'Failed', variant: 'error' },
  canceled: { label: 'Canceled', variant: 'inactive' },
}

export const CONNECTION_STATUS: Record<string, StatusAppearance> = {
  active: { label: 'Active', variant: 'success' },
  invalid: { label: 'Invalid', variant: 'error' },
}

export const SIGNING_IDENTITY_STATUS: Record<string, StatusAppearance> = {
  active: { label: 'Active', variant: 'success' },
  expiring: { label: 'Expiring', variant: 'warning' },
  expired: { label: 'Expired', variant: 'error' },
  revoked: { label: 'Revoked', variant: 'inactive' },
}

export const ARTIFACT_STATUS: Record<string, StatusAppearance> = {
  uploading: { label: 'Uploading', variant: 'processing' },
  processing: { label: 'Processing', variant: 'processing' },
  ready: { label: 'Ready', variant: 'success' },
  invalid: { label: 'Invalid', variant: 'error' },
}

export const SUBMISSION_STATUS: Record<string, StatusAppearance> = {
  waiting: { label: 'Waiting', variant: 'pending' },
  validating: { label: 'Validating', variant: 'processing' },
  uploading: { label: 'Uploading', variant: 'processing' },
  processing: { label: 'Processing', variant: 'processing' },
  pending: { label: 'Pending', variant: 'warning' },
  reviewing: { label: 'In review', variant: 'processing' },
  approved: { label: 'Approved', variant: 'info' },
  published: { label: 'Published', variant: 'success' },
  rejected: { label: 'Rejected', variant: 'error' },
  halted: { label: 'Halted', variant: 'warning' },
  failed: { label: 'Failed', variant: 'error' },
  canceled: { label: 'Canceled', variant: 'inactive' },
}

export const RELEASE_STATUS: Record<string, StatusAppearance> = {
  draft: { label: 'Draft', variant: 'inactive' },
  active: { label: 'Active', variant: 'processing' },
  published: { label: 'Published', variant: 'success' },
  attention: { label: 'Needs attention', variant: 'error' },
}

export const CHECK_SEVERITY: Record<string, StatusAppearance> = {
  error: { label: 'Blocker', variant: 'error' },
  warning: { label: 'Warning', variant: 'warning' },
}

export function statusAppearance(
  status: string,
  map: Record<string, StatusAppearance>,
): StatusAppearance {
  if (Object.hasOwn(map, status)) return map[status]
  const label = status
    ? status.charAt(0).toUpperCase() + status.slice(1)
    : 'Unknown'
  return { label, variant: 'info' }
}

const ACTIVE_BUILD_STATUSES: readonly string[] = [
  'waiting',
  'processing',
  'building',
  'signing',
]

const PENDING_ARTIFACT_STATUSES: readonly string[] = ['uploading', 'processing']

const CANCELABLE_SUBMISSION_STATUSES: readonly string[] = [
  'waiting',
  'validating',
  'uploading',
  'processing',
  'pending',
  'reviewing',
  'approved',
]

const RELEASED_SUBMISSION_STEPS: readonly string[] = ['released', 'settled']

export const ACTIVE_SUBMISSION_STATUSES: readonly string[] = [
  ...CANCELABLE_SUBMISSION_STATUSES,
  'halted',
]

export function isBuildActive(status: string): boolean {
  return ACTIVE_BUILD_STATUSES.includes(status)
}

export function isArtifactPending(status: string): boolean {
  return PENDING_ARTIFACT_STATUSES.includes(status)
}

function isRollingOut(status: string, rollout: number): boolean {
  return status === 'published' && rollout < 1
}

export function isSubmissionActive(status: string, rollout: number): boolean {
  return (
    ACTIVE_SUBMISSION_STATUSES.includes(status) || isRollingOut(status, rollout)
  )
}

export type SubmissionState = {
  status: string
  rollout: number
  automatic: boolean
  provider: string
  step?: string
}

export type SubmissionActions = {
  cancel: boolean
  halt: boolean
  resume: boolean
  publish: boolean
  rollout: boolean
}

export function submissionActions({
  status,
  rollout,
  automatic,
  provider,
  step = '',
}: SubmissionState): SubmissionActions {
  const rollingOut = isRollingOut(status, rollout)
  const halted = status === 'halted'
  const releasedOnGoogle =
    provider === 'google' && RELEASED_SUBMISSION_STEPS.includes(step)
  return {
    cancel:
      CANCELABLE_SUBMISSION_STATUSES.includes(status) && !releasedOnGoogle,
    halt: rollingOut,
    resume: halted,
    publish: status === 'approved' && !automatic,
    rollout: provider === 'google' && (rollingOut || halted),
  }
}

export function rolloutPercent(rollout: number): number {
  return Math.round(rollout * 100)
}
