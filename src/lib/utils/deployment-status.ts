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
 * Deployment status badge configuration
 */
export interface DeploymentStatusBadge {
  label: string
  badgeVariant: 'completed' | 'failed' | 'pending' | 'processing'
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
      badgeVariant: 'completed' | 'failed' | 'pending' | 'processing'
      icon: typeof CheckCircle2
    }
  > = {
    ready: { label: 'Ready', badgeVariant: 'completed', icon: CheckCircle2 },
    building: {
      label: 'Building',
      badgeVariant: 'processing',
      icon: CircleDashed,
    },
    processing: {
      label: 'Processing',
      badgeVariant: 'processing',
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
  return status === 'building' || status === 'processing'
}

/**
 * Check if a deployment has completed (ready or failed).
 * Build output download is only available for completed deployments.
 */
export function isDeploymentCompleted(
  status: string | undefined | null,
): boolean {
  return status === 'ready' || status === 'failed'
}
