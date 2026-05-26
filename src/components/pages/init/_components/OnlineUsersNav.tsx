import type {
  LaunchEvent,
  LaunchEventOnlineUser,
  LaunchEventUserPresence,
} from '@/lib/init/types'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { ChevronLeft, X } from 'lucide-react'
import { useState } from 'react'

interface OnlineUsersNavProps {
  event: LaunchEvent
  mobileOpen: boolean
  onMobileClose: () => void
  /** When true, render the panel even if the user list is still loading or empty. */
  showPanel?: boolean
}

type AvatarSize = 'sm' | 'md'

function PresenceAvatar({
  user,
  presence,
  size = 'sm',
}: {
  user: LaunchEventOnlineUser
  presence: LaunchEventUserPresence
  size?: AvatarSize
}) {
  const ringClass =
    presence === 'online'
      ? user.isLive
        ? 'bg-gradient-to-tr from-[var(--brand-cta)] via-[#ff6b9d] to-[var(--brand-cta)]'
        : 'bg-gradient-to-tr from-emerald-400 via-emerald-500 to-teal-400'
      : 'bg-gradient-to-tr from-muted-foreground/35 via-muted-foreground/20 to-muted-foreground/35'

  return (
    <span
      className={cn('inline-flex shrink-0 rounded-full p-[2px]', ringClass)}
      aria-hidden
    >
      <InitialsAvatar
        name={user.name}
        size={size}
        className="rounded-full ring-2 ring-background"
      />
    </span>
  )
}

function renderUserRow(
  user: LaunchEventOnlineUser,
  presence: LaunchEventUserPresence,
  collapsed: boolean,
  isMobile = false,
) {
  const avatarSize: AvatarSize = isMobile ? 'md' : 'sm'

  const row = (
    <div
      className={cn(
        'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-[13px] font-medium transition-colors duration-150',
        'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
        collapsed && !isMobile && 'justify-center px-0',
        isMobile && 'gap-3 px-3 py-2.5 text-[14px]',
      )}
    >
      <PresenceAvatar user={user} presence={presence} size={avatarSize} />
      {(!collapsed || isMobile) && (
        <>
          <div className="min-w-0 flex-1 text-left">
            <p className="truncate text-[13px] font-medium text-foreground">
              {user.name}
            </p>
            <p className="truncate text-[12px] font-normal text-muted-foreground">
              {user.activity}
            </p>
          </div>
        </>
      )}
    </div>
  )

  if (collapsed && !isMobile) {
    return (
      <Tooltip key={user.id} delayDuration={0}>
        <TooltipTrigger asChild>{row}</TooltipTrigger>
        <TooltipContent side="right" sideOffset={8}>
          <p className="font-medium">{user.name}</p>
          <p className="text-muted-foreground">{user.activity}</p>
        </TooltipContent>
      </Tooltip>
    )
  }

  return <div key={user.id}>{row}</div>
}

function UserCategory({
  label,
  users,
  presence,
  collapsed,
  isMobile = false,
}: {
  label: string
  users: LaunchEventOnlineUser[]
  presence: LaunchEventUserPresence
  collapsed: boolean
  isMobile?: boolean
}) {
  if (users.length === 0) return null

  return (
    <div className="space-y-0.5">
      {(!collapsed || isMobile) && (
        <p
          className={cn(
            'mb-1.5 px-2.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60',
            isMobile && 'px-3',
          )}
        >
          {label}
        </p>
      )}
      {users.map((user) => renderUserRow(user, presence, collapsed, isMobile))}
    </div>
  )
}

function OnlineUsersNavContent({
  event,
  collapsed,
  isMobile = false,
  showPanel = false,
}: {
  event: LaunchEvent
  collapsed: boolean
  isMobile?: boolean
  showPanel?: boolean
}) {
  const hasUsers =
    event.onlineUsers.length > 0 || event.recentlyOnlineUsers.length > 0

  if (showPanel && !hasUsers) {
    return (
      <p
        className={cn(
          'px-2.5 text-[13px] text-muted-foreground',
          isMobile && 'px-3',
          collapsed && !isMobile && 'text-center text-[12px]',
        )}
      >
        {collapsed && !isMobile ? '…' : 'No one else online yet. You are connected.'}
      </p>
    )
  }

  return (
    <>
      <UserCategory
        label="Online now"
        users={event.onlineUsers}
        presence="online"
        collapsed={collapsed}
        isMobile={isMobile}
      />
      <UserCategory
        label="Recently online"
        users={event.recentlyOnlineUsers}
        presence="recent"
        collapsed={collapsed}
        isMobile={isMobile}
      />
    </>
  )
}

function OnlineUsersFooter({
  event,
  collapsed,
  isMobile = false,
}: {
  event: LaunchEvent
  collapsed: boolean
  isMobile?: boolean
}) {
  const totalLabel = `${event.onlineCount.toLocaleString()} online`

  if (collapsed && !isMobile) {
    return (
      <Tooltip delayDuration={0}>
        <TooltipTrigger asChild>
          <div className="flex h-full w-full items-center justify-center px-0">
            <span className="text-[11px] font-semibold tabular-nums text-muted-foreground">
              {event.onlineCount > 999 ? '999+' : event.onlineCount}
            </span>
          </div>
        </TooltipTrigger>
        <TooltipContent side="right" sideOffset={8}>
          <p>{totalLabel}</p>
          {event.othersOnlineCount > 0 ? (
            <p className="text-muted-foreground">
              +{event.othersOnlineCount.toLocaleString()} others
            </p>
          ) : null}
        </TooltipContent>
      </Tooltip>
    )
  }

  return (
    <div className={cn('px-2.5 py-1', isMobile && 'px-3')}>
      <p className="text-[12px] font-medium text-foreground">{totalLabel}</p>
      {event.othersOnlineCount > 0 ? (
        <p className="mt-0.5 text-[11px] text-muted-foreground">
          +{event.othersOnlineCount.toLocaleString()} others participating
        </p>
      ) : null}
    </div>
  )
}

export function hasOnlineUsersNav(
  event: LaunchEvent,
  options?: { presenceEnabled?: boolean; isAuthenticated?: boolean },
) {
  if (options?.presenceEnabled && options.isAuthenticated) {
    return true
  }
  return event.onlineUsers.length > 0 || event.recentlyOnlineUsers.length > 0
}

export function OnlineUsersNav({
  event,
  mobileOpen,
  onMobileClose,
  showPanel = false,
}: OnlineUsersNavProps) {
  const [collapsed, setCollapsed] = useState(false)

  if (!showPanel && !hasOnlineUsersNav(event)) return null

  return (
    <TooltipProvider>
      <div
        className={cn(
          'relative z-20 hidden h-full flex-shrink-0 @[1024px]:block',
          'transition-[width] duration-150 ease-out',
          collapsed ? 'w-[60px]' : 'w-[220px]',
        )}
      >
        <aside
          className={cn(
            'flex h-full w-full flex-col overflow-hidden border-r border-border bg-background',
            '[transform:translateZ(0)] [backface-visibility:hidden]',
          )}
        >
          <nav
            className="flex-1 space-y-6 overflow-y-auto px-3 py-4"
            role="navigation"
            aria-label="Online participants"
          >
            <OnlineUsersNavContent event={event} collapsed={collapsed} showPanel={showPanel} />
          </nav>

          <div className="flex h-[54px] w-full items-center border-t border-border px-3">
            <OnlineUsersFooter event={event} collapsed={collapsed} />
          </div>
        </aside>

        <button
          type="button"
          onClick={() => setCollapsed(!collapsed)}
          className="absolute right-0 top-1/2 z-10 flex h-6 w-6 shrink-0 -translate-y-1/2 translate-x-1/2 cursor-pointer items-center justify-center rounded-md border border-border bg-card text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          aria-label={collapsed ? 'Expand online panel' : 'Collapse online panel'}
        >
          <ChevronLeft
            className={cn(
              'h-3.5 w-3.5 transition-transform duration-200',
              collapsed && 'rotate-180',
            )}
          />
        </button>
      </div>

      <aside
        className={cn(
          'fixed left-0 top-0 z-[130] flex h-[100dvh] max-h-[100dvh] w-[280px] flex-col overflow-hidden border-r border-border bg-background',
          'transition-transform duration-200 ease-out [backface-visibility:hidden]',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        )}
        role="dialog"
        aria-modal="true"
        aria-label="Online participants"
        inert={!mobileOpen ? true : undefined}
      >
        <div className="flex h-14 items-center justify-between border-b border-border px-4">
          <p className="text-[14px] font-semibold text-foreground">Who&apos;s online</p>
          <button
            type="button"
            onClick={onMobileClose}
            className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label="Close online panel"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <nav
          className="flex-1 space-y-6 overflow-y-auto px-4 py-4"
          role="navigation"
          aria-label="Mobile online participants"
        >
          <OnlineUsersNavContent
            event={event}
            collapsed={false}
            isMobile
            showPanel={showPanel}
          />
        </nav>

        <div className="border-t border-border px-4 py-3">
          <OnlineUsersFooter event={event} collapsed={false} isMobile />
        </div>
      </aside>
    </TooltipProvider>
  )
}
