import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { cn } from '@/lib/utils'

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
  const linkClassName = cn(
    'font-medium text-foreground underline hover:no-underline',
    className,
  )

  if (!getActiveProfileFeatures().billing) {
    return <span className={linkClassName}>{children}</span>
  }

  if (orgId) {
    return (
      <Link to="/upgrade" search={{ orgId }} className={linkClassName}>
        {children}
      </Link>
    )
  }

  return (
    <Link to="/upgrade" className={linkClassName}>
      {children}
    </Link>
  )
}
