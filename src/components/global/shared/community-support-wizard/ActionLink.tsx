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

export function ActionLink({
  action,
  className,
  onAction,
  children,
}: ActionLinkProps) {
  if (action.external) {
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
