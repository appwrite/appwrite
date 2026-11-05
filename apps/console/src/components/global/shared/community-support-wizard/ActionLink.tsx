'use client'

import type { ReactNode } from 'react'
import {
  COMMUNITY_SUPPORT_ACTIONS,
  type CommunitySupportAction,
  type CommunitySupportActionId,
} from '@/lib/community/support-prompt'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'

type ActionLinkProps = {
  action: CommunitySupportAction
  className?: string
  onAction: (actionId: CommunitySupportActionId) => void
  children: ReactNode
}

function isAbsoluteHttpUrl(href: string): boolean {
  return /^https?:\/\//i.test(href.trim())
}

export function ActionLink({
  action,
  className,
  onAction,
  children,
}: ActionLinkProps) {
  // Always open in a new tab so the wizard is not replaced by the destination.
  if (isAbsoluteHttpUrl(action.href)) {
    return (
      <a
        href={action.href}
        target="_blank"
        rel="noopener noreferrer"
        className={className}
        data-analytics-track="manual"
        onClick={() => onAction(action.id)}
      >
        {children}
      </a>
    )
  }

  return (
    <MarketingSiteLink
      href={action.href}
      target="_blank"
      rel="noopener noreferrer"
      className={className}
      data-analytics-track="manual"
      onClick={() => onAction(action.id)}
    >
      {children}
    </MarketingSiteLink>
  )
}

export function getAction(id: CommunitySupportActionId): CommunitySupportAction {
  const action = COMMUNITY_SUPPORT_ACTIONS.find((item) => item.id === id)
  if (!action) {
    throw new Error(`Unknown community support action: ${id}`)
  }
  return action
}
