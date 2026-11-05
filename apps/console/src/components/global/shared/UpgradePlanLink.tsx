import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { cn } from '@/lib/utils'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

type UpgradePlanLinkProps = {
  orgId?: string | null
  children?: ReactNode
  className?: string
}

/**
 * Inline link to the fullscreen upgrade wizard (`/upgrade`).
 * Renders plain text when billing is disabled (e.g. self-hosted).
 */
export function UpgradePlanLink({
  orgId,
  children = 'Upgrade your plan',
  className,
}: UpgradePlanLinkProps) {
  const t = useT()
  const linkClassName = cn('link-neutral', className)
  const content = typeof children === 'string' ? t(children) : children

  if (!getActiveProfileFeatures().billing) {
    return <span className={linkClassName}>{content}</span>
  }

  if (orgId) {
    return (
      <Link
        to="/upgrade"
        search={{ orgId }}
        className={linkClassName}
        {...analyticsAttrs('upgrade-clicked')}
      >
        {content}
      </Link>
    )
  }

  return (
    <Link
      to="/upgrade"
      className={linkClassName}
      {...analyticsAttrs('upgrade-clicked')}
    >
      {content}
    </Link>
  )
}
