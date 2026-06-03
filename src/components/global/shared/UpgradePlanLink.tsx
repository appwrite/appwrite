import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { cn } from '@/lib/utils'

type UpgradePlanLinkProps = {
  orgId?: string | null
  children?: ReactNode
  className?: string
  'data-analytics-id'?: string
  'data-analytics-surface'?: string
  'data-analytics-resource'?: string
}

/**
 * Inline link to the fullscreen upgrade wizard (`/upgrade`).
 * Renders plain text when billing is disabled (e.g. self-hosted).
 */
export function UpgradePlanLink({
  orgId,
  children = 'Upgrade your plan',
  className,
  'data-analytics-id': analyticsId = 'upgrade_plan_link',
  'data-analytics-surface': analyticsSurface,
  'data-analytics-resource': analyticsResource,
}: UpgradePlanLinkProps) {
  const linkClassName = cn(
    'font-medium text-foreground underline hover:no-underline',
    className,
  )

  if (!getActiveProfileFeatures().billing) {
    return <span className={linkClassName}>{children}</span>
  }

  const analyticsProps = {
    'data-analytics-id': analyticsId,
    ...(analyticsSurface
      ? { 'data-analytics-surface': analyticsSurface }
      : {}),
    ...(analyticsResource
      ? { 'data-analytics-resource': analyticsResource }
      : {}),
  }

  if (orgId) {
    return (
      <Link
        to="/upgrade"
        search={{ orgId }}
        className={linkClassName}
        {...analyticsProps}
      >
        {children}
      </Link>
    )
  }

  return (
    <Link to="/upgrade" className={linkClassName} {...analyticsProps}>
      {children}
    </Link>
  )
}
