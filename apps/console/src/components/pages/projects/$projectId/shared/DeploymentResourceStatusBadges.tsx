import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import {
  getDeploymentStatusBadge,
  isDeploymentInProgress,
} from '@/lib/utils/deployment-status'
import { useT } from '@/lib/i18n/translate'

export type DeploymentResourceListItem = {
  enabled?: boolean
  live?: boolean
  latestDeploymentStatus?: string
  latestDeploymentCreatedAt?: string
  deploymentCreatedAt?: string
}

export function getActiveDeploymentCreatedAt(
  resource: DeploymentResourceListItem,
): string | undefined {
  return (
    resource.deploymentCreatedAt ||
    resource.latestDeploymentCreatedAt ||
    undefined
  )
}

export function resourceHasInProgressDeployment(
  resource: DeploymentResourceListItem,
): boolean {
  return isDeploymentInProgress(resource.latestDeploymentStatus ?? '')
}

export function resourceHasVisibleStatus(
  resource: DeploymentResourceListItem,
): boolean {
  if (resource.enabled === false) return true
  if (!resource.live) return true

  const deploymentStatus = resource.latestDeploymentStatus
  if (!deploymentStatus) return false

  const deploymentBadge = getDeploymentStatusBadge(
    deploymentStatus,
    resource.latestDeploymentCreatedAt,
  )

  return (
    deploymentBadge.badgeVariant === 'failed' ||
    deploymentBadge.badgeVariant === 'deploymentBuilding' ||
    deploymentBadge.badgeVariant === 'pending'
  )
}

export function DeploymentResourceStatusBadges({
  resource,
}: {
  resource: DeploymentResourceListItem
}) {
  const t = useT()
  const badgeClassName = 'gap-1.5 text-[11px] font-medium border px-2 py-0.5'

  if (resource.enabled === false) {
    return (
      <Badge variant="error" className={badgeClassName}>
        {t('Disabled')}
      </Badge>
    )
  }

  const badges: ReactNode[] = []
  const deploymentStatus = resource.latestDeploymentStatus

  if (deploymentStatus) {
    const deploymentBadge = getDeploymentStatusBadge(
      deploymentStatus,
      resource.latestDeploymentCreatedAt,
    )
    const isAttentionStatus =
      deploymentBadge.badgeVariant === 'failed' ||
      deploymentBadge.badgeVariant === 'deploymentBuilding' ||
      deploymentBadge.badgeVariant === 'pending'

    if (isAttentionStatus) {
      const StatusIcon = deploymentBadge.icon
      badges.push(
        <Badge
          key="deployment"
          variant={deploymentBadge.badgeVariant}
          className={badgeClassName}
        >
          <StatusIcon className="h-3 w-3" />
          {t(deploymentBadge.label)}
        </Badge>,
      )
    }
  }

  if (!resource.live) {
    badges.push(
      <Badge key="redeploy" variant="warning" className={badgeClassName}>
        {t('Redeploy')}
      </Badge>,
    )
  }

  if (badges.length === 0) {
    return null
  }

  return <div className="flex flex-wrap items-center gap-1.5">{badges}</div>
}
