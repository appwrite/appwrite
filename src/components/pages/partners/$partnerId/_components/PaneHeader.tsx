import type { ReactNode } from 'react'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import {
  PARTNER_PANE_HEADER_ACTIONS_CLASSNAME,
  PARTNER_PANE_HEADER_CLASSNAME,
  PARTNER_PANE_HEADER_PRIMARY_ACTION_CLASSNAME,
  PARTNER_PANE_HEADER_REFRESH_BUTTON_CLASSNAME,
  PARTNER_PANE_HEADER_SECONDARY_ACTIONS_CLASSNAME,
  PARTNER_PANE_TITLE_CLASSNAME,
  PARTNER_PROJECTS_PANE_HEADER_WRAP_CLASSNAME,
  PARTNER_PROJECTS_PANE_TOOLBAR_CLASSNAME,
} from '@/components/pages/partners/$partnerId/_components/pane-header'
import { cn } from '@/lib/utils'

/** First title row for any right-column pane (with or without CTAs). */
export function PartnerPaneTitleRow({
  title,
  children,
  className,
}: {
  title: string
  children?: ReactNode
  className?: string
}) {
  return (
    <div className={cn(PARTNER_PANE_HEADER_CLASSNAME, className)}>
      <h2 className={PARTNER_PANE_TITLE_CLASSNAME}>{title}</h2>
      <div className={PARTNER_PANE_HEADER_ACTIONS_CLASSNAME}>{children}</div>
    </div>
  )
}

/** Two-row partner pane chrome: title + actions, then search/toolbar. */
export function PartnerTwoRowPaneHeader({
  title,
  actions,
  primaryAction,
  toolbar,
  onReload,
  isReloading,
}: {
  title: string
  actions?: ReactNode
  /** Primary CTA last in the trailing actions group. */
  primaryAction?: ReactNode
  toolbar?: ReactNode
  onReload?: () => void
  isReloading?: boolean
}) {
  const showToolbar = Boolean(toolbar || onReload)

  return (
    <div className={PARTNER_PROJECTS_PANE_HEADER_WRAP_CLASSNAME}>
      <PartnerPaneTitleRow title={title}>
        {actions ? (
          <div className={PARTNER_PANE_HEADER_SECONDARY_ACTIONS_CLASSNAME}>
            {actions}
          </div>
        ) : null}
        {primaryAction ? (
          <div className={PARTNER_PANE_HEADER_PRIMARY_ACTION_CLASSNAME}>
            {primaryAction}
          </div>
        ) : null}
      </PartnerPaneTitleRow>
      {showToolbar ? (
        <div className={PARTNER_PROJECTS_PANE_TOOLBAR_CLASSNAME}>
          {toolbar}
          {onReload ? (
            <RefreshButton
              onClick={onReload}
              isRefreshing={isReloading}
              className={PARTNER_PANE_HEADER_REFRESH_BUTTON_CLASSNAME}
            />
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
