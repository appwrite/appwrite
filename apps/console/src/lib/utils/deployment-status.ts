/**
 * Deployment Status Utilities
 *
 * Provides standardized deployment status badge logic with timeout detection.
 * If a deployment is not 'ready' or 'failed' after 30 minutes, it's marked as 'timeout'.
 */

import {
  CheckCircle2,
  CircleDashed,
  Clock,
  AlertCircle,
  type LucideIcon,
} from 'lucide-react'

/**
 * Fixed width for deployment table Status column (longest label: "Processing").
 * Prevents layout shift when status text changes during polling.
 */
export const DEPLOYMENT_TABLE_STATUS_COLUMN_CLASS =
  'w-[7.75rem] min-w-[7.75rem] max-w-[7.75rem]'

/**
 * Deployment status badge configuration
 */
export interface DeploymentStatusBadge {
  label: string
  badgeVariant:
    | 'deploymentReady'
    | 'deploymentBuilding'
    | 'failed'
    | 'pending'
  icon: LucideIcon
}

/**
 * Timeout threshold: 30 minutes in milliseconds
 */
const DEPLOYMENT_TIMEOUT_MS = 30 * 60 * 1000 // 30 minutes

/**
 * Check if a deployment should be marked as timeout
 *
 * @param status - Current deployment status
 * @param createdAt - ISO date string of when the deployment was created
 * @returns True if deployment should be marked as timeout
 */
export function isDeploymentTimeout(
  status: string,
  createdAt?: string | null,
): boolean {
  // If status is already terminal or canceled, never show as timeout
  if (
    status === 'ready' ||
    status === 'failed' ||
    status === 'canceled' ||
    status === 'cancelled'
  ) {
    return false
  }

  // If no createdAt timestamp, can't determine timeout
  if (!createdAt) {
    return false
  }

  try {
    const createdTime = new Date(createdAt).getTime()
    const now = Date.now()
    const elapsed = now - createdTime

    // Mark as timeout if 30 minutes have passed
    return elapsed >= DEPLOYMENT_TIMEOUT_MS
  } catch {
    // Invalid date, can't determine timeout
    return false
  }
}

/**
 * Get deployment status badge configuration
 *
 * @param status - Current deployment status
 * @param createdAt - Optional ISO date string of when the deployment was created
 * @returns Badge configuration with label, variant, and icon
 */
export function getDeploymentStatusBadge(
  status: string,
  createdAt?: string | null,
): DeploymentStatusBadge {
  // Check for timeout first
  if (isDeploymentTimeout(status, createdAt)) {
    return {
      label: 'Timeout',
      badgeVariant: 'failed',
      icon: AlertCircle,
    }
  }

  const statusMap: Record<
    string,
    {
      label: string
      badgeVariant:
        | 'deploymentReady'
        | 'deploymentBuilding'
        | 'failed'
        | 'pending'
      icon: typeof CheckCircle2
    }
  > = {
    ready: { label: 'Ready', badgeVariant: 'deploymentReady', icon: CheckCircle2 },
    building: {
      label: 'Building',
      badgeVariant: 'deploymentBuilding',
      icon: CircleDashed,
    },
    processing: {
      label: 'Processing',
      badgeVariant: 'deploymentBuilding',
      icon: CircleDashed,
    },
    waiting: { label: 'Waiting', badgeVariant: 'pending', icon: Clock },
    failed: { label: 'Failed', badgeVariant: 'failed', icon: AlertCircle },
    canceled: {
      label: 'Canceled',
      badgeVariant: 'pending',
      icon: AlertCircle,
    },
    cancelled: {
      label: 'Canceled',
      badgeVariant: 'pending',
      icon: AlertCircle,
    },
    timeout: {
      label: 'Timeout',
      badgeVariant: 'failed',
      icon: AlertCircle,
    },
  }

  const statusInfo = statusMap[status] || {
    label: status,
    badgeVariant: 'pending' as const,
    icon: Clock,
  }

  return {
    label: statusInfo.label,
    badgeVariant: statusInfo.badgeVariant,
    icon: statusInfo.icon,
  }
}

/**
 * Check if a deployment is currently in progress (building or processing).
 * Used to show live elapsed duration instead of final buildDuration.
 */
export function isDeploymentInProgress(status: string): boolean {
  return (
    status === 'building' ||
    status === 'processing' ||
    status === 'waiting'
  )
}

/**
 * Check if a deployment has completed (ready or failed).
 */
export function isDeploymentCompleted(
  status: string | undefined | null,
): boolean {
  return status === 'ready' || status === 'failed'
}

/**
 * Build output download is only available for ready deployments.
 * Failed and in-progress deployments do not have output (the API returns 404).
 */
export function canDownloadDeploymentBuildOutput(
  status: string | undefined | null,
): boolean {
  return status === 'ready'
}

/**
 * Deployment to rebuild when settings are not live.
 * Prefer latest (includes failed builds) over the active ready deployment,
 * since settings are often changed after a failed deploy.
 */
export function getRedeploySourceDeploymentId(
  resource?: {
    latestDeploymentId?: string | null
    deploymentId?: string | null
  } | null,
): string | undefined {
  return resource?.latestDeploymentId || resource?.deploymentId || undefined
}

/** Merge a redeploy response into cached function/site data for immediate UI updates. */
export function patchResourceAfterRedeploy<
  T extends {
    latestDeploymentId?: string | null
    latestDeploymentStatus?: string
    latestDeploymentCreatedAt?: string
  },
>(
  resource: T,
  deployment: { $id: string; status: string; $createdAt?: string },
): T {
  return {
    ...resource,
    latestDeploymentId: deployment.$id,
    latestDeploymentStatus: deployment.status,
    latestDeploymentCreatedAt: new Date().toISOString(),
  }
}

export function keepNewerLatestDeployment<
  T extends {
    live?: boolean
    latestDeploymentId?: string | null
    latestDeploymentStatus?: string
    latestDeploymentCreatedAt?: string
  },
>(previous: T | undefined, incoming: T): T {
  if (!previous || incoming.live) return incoming

  const newerCreatedAt = newerTimestamp(
    previous.latestDeploymentCreatedAt,
    incoming.latestDeploymentCreatedAt,
  )
  if (newerCreatedAt !== previous.latestDeploymentCreatedAt) {
    return incoming
  }

  return {
    ...incoming,
    latestDeploymentId: previous.latestDeploymentId,
    latestDeploymentStatus: previous.latestDeploymentStatus,
    latestDeploymentCreatedAt: previous.latestDeploymentCreatedAt,
  }
}

function parseTimestamp(value?: string | null): number | null {
  if (!value) return null
  const time = Date.parse(value)
  return Number.isFinite(time) ? time : null
}

function newerTimestamp(
  left?: string | null,
  right?: string | null,
): string | undefined {
  const leftTime = parseTimestamp(left)
  const rightTime = parseTimestamp(right)
  if (leftTime == null) return right ?? undefined
  if (rightTime == null) return left ?? undefined
  return rightTime > leftTime ? (right ?? undefined) : (left ?? undefined)
}

/**
 * True when the latest build started at or after the resource's last update.
 * Used so an in-progress build that predates a settings change does not
 * replace the "settings are not live" alert.
 */
export function isLatestBuildAfterResourceUpdate(
  resource?: {
    $updatedAt?: string
    latestDeploymentCreatedAt?: string
  } | null,
  activeDeployment?: { $createdAt?: string | null } | null,
): boolean {
  const updatedAt = parseTimestamp(resource?.$updatedAt)
  const buildCreatedAt = parseTimestamp(
    newerTimestamp(
      resource?.latestDeploymentCreatedAt,
      activeDeployment?.$createdAt,
    ),
  )
  if (updatedAt == null || buildCreatedAt == null) return false
  return buildCreatedAt >= updatedAt
}

/**
 * The blue in-progress banner should replace the yellow settings alert only
 * when a build is running and that build was created after the last update.
 * While settings are live, any in-progress build can show the blue banner.
 */
export function shouldShowBuildingInsteadOfSettingsAlert(
  resource?: {
    live?: boolean
    $updatedAt?: string
    latestDeploymentCreatedAt?: string
    latestDeploymentStatus?: string
  } | null,
  activeDeployment?: {
    status?: string | null
    $createdAt?: string | null
  } | null,
  settingsRedeployPending = false,
): boolean {
  const building =
    settingsRedeployPending || resourceIsBuilding(resource, activeDeployment)
  if (!building) return false
  if (resource?.live !== false) return true
  return isLatestBuildAfterResourceUpdate(resource, activeDeployment)
}

export function resourceIsBuilding(
  resource?: {
    latestDeploymentStatus?: string
  } | null,
  activeDeployment?: { status?: string | null } | null,
): boolean {
  if (isDeploymentInProgress(resource?.latestDeploymentStatus ?? '')) {
    return true
  }
  return isDeploymentInProgress(activeDeployment?.status ?? '')
}
