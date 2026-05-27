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
import { AnimatePresence, motion, useReducedMotion } from 'motion/react'
import { useState } from 'react'
import { InitPresenceStatusControl } from './InitPresenceStatusControl'

interface OnlineUsersNavProps {
  event: LaunchEvent
  mobileOpen: boolean
  onMobileClose: () => void
  /** When true, render the panel even if the user list is still loading or empty. */
  showPanel?: boolean
}

type AvatarSize = 'sm' | 'md'

const PRESENCE_LIST_EASE: [number, number, number, number] = [0.22, 1, 0.36, 1]
const PRESENCE_LIST_TRANSITION = { duration: 0.22, ease: PRESENCE_LIST_EASE }
const PRESENCE_ACTIVITY_TRANSITION = { duration: 0.16, ease: PRESENCE_LIST_EASE }
const PRESENCE_RING_PULSE_TRANSITION = { duration: 0.42, ease: PRESENCE_LIST_EASE }
const APPWRITE_PRESENCES_DOCS_URL = 'https://appwrite.io/docs/apis/realtime/presences'

function buildPresenceStatusKey(
  user: LaunchEventOnlineUser,
  presence: LaunchEventUserPresence,
): string {
  return `${presence}:${user.isLive ? 'live' : 'idle'}:${user.activity}`
}

/** Readable popover-style tooltip for collapsed sidebar rows (not inverted xs pills). */
const ONLINE_USER_TOOLTIP_CLASS =
  'max-w-[min(280px,calc(100dvw-5rem))] border border-border bg-popover px-3 py-2.5 text-popover-foreground shadow-md [&_svg]:!hidden'

function OnlineUserTooltipDetails({
  name,
  activity,
}: {
  name: string
  activity: string
}) {
  return (
    <div className="space-y-1 text-left">
      <p className="text-[13px] font-semibold leading-snug text-foreground">{name}</p>
      <p className="text-[12px] leading-relaxed text-muted-foreground/70">{activity}</p>
    </div>
  )
}

function PresenceAvatar({
  user,
  presence,
  size = 'sm',
}: {
  user: LaunchEventOnlineUser
  presence: LaunchEventUserPresence
  size?: AvatarSize
}) {
  const reduceMotion = useReducedMotion()
  const statusKey = buildPresenceStatusKey(user, presence)
  const ringClass =
    presence === 'online'
      ? user.isLive
        ? 'bg-gradient-to-tr from-[var(--brand-cta)] via-[#ff6b9d] to-[var(--brand-cta)]'
        : 'bg-gradient-to-tr from-emerald-400 via-emerald-500 to-teal-400'
      : 'bg-gradient-to-tr from-muted-foreground/35 via-muted-foreground/20 to-muted-foreground/35'

  return (
    <motion.span
      key={statusKey}
      className={cn('inline-flex shrink-0 rounded-full p-[2px]', ringClass)}
      animate={reduceMotion ? undefined : { scale: [1, 1.14, 1] }}
      transition={PRESENCE_RING_PULSE_TRANSITION}
      aria-hidden
    >
      <InitialsAvatar
        name={user.name}
        size={size}
        className="rounded-full ring-2 ring-background"
      />
    </motion.span>
  )
}

function OnlineUserActivity({ activity }: { activity: string }) {
  const reduceMotion = useReducedMotion()

  if (reduceMotion) {
    return (
      <p
        className="truncate text-[12px] font-normal text-muted-foreground"
        title={activity}
      >
        {activity}
      </p>
    )
  }

  return (
    <div className="relative min-h-[1lh] overflow-hidden">
      <AnimatePresence initial={false} mode="wait">
        <motion.p
          key={activity}
          initial={{ opacity: 0, y: 2 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: -2 }}
          transition={PRESENCE_ACTIVITY_TRANSITION}
          className="truncate text-[12px] font-normal text-muted-foreground"
          title={activity}
        >
          {activity}
        </motion.p>
      </AnimatePresence>
    </div>
  )
}

function OnlineUserRow({
  user,
  presence,
  collapsed,
  isMobile = false,
}: {
  user: LaunchEventOnlineUser
  presence: LaunchEventUserPresence
  collapsed: boolean
  isMobile?: boolean
}) {
  const reduceMotion = useReducedMotion()
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
        <div className="min-w-0 flex-1 text-left">
          <p className="truncate text-[13px] font-medium text-foreground">{user.name}</p>
          <OnlineUserActivity activity={user.activity} />
        </div>
      )}
    </div>
  )

  const animatedRow = (
    <motion.div
      layout={!reduceMotion}
      initial={reduceMotion ? false : { opacity: 0, y: -6 }}
      animate={{ opacity: 1, y: 0 }}
      exit={reduceMotion ? undefined : { opacity: 0, y: -6 }}
      transition={PRESENCE_LIST_TRANSITION}
    >
      {collapsed && !isMobile ? (
        <Tooltip delayDuration={200}>
          <TooltipTrigger asChild>
            <button
              type="button"
              className={cn(
                'flex w-full cursor-default items-center justify-center rounded-md px-0 py-1.5',
                'transition-colors duration-150 hover:bg-accent/50',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
              )}
              aria-label={`${user.name}. ${user.activity}`}
            >
              <PresenceAvatar user={user} presence={presence} size={avatarSize} />
            </button>
          </TooltipTrigger>
          <TooltipContent
            side="right"
            sideOffset={12}
            className={ONLINE_USER_TOOLTIP_CLASS}
          >
            <OnlineUserTooltipDetails name={user.name} activity={user.activity} />
          </TooltipContent>
        </Tooltip>
      ) : (
        row
      )}
    </motion.div>
  )

  return animatedRow
}

function UserCategoryCount({ count }: { count: number }) {
  const reduceMotion = useReducedMotion()

  const countClassName =
    'shrink-0 text-[11px] font-medium tabular-nums text-muted-foreground/60'

  if (reduceMotion) {
    return <span className={countClassName}>{count}</span>
  }

  return (
    <motion.span
      key={count}
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      transition={PRESENCE_ACTIVITY_TRANSITION}
      className={countClassName}
    >
      {count}
    </motion.span>
  )
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
  const reduceMotion = useReducedMotion()

  return (
    <AnimatePresence initial={false}>
      {users.length > 0 ? (
        <motion.div
          key={label}
          layout={!reduceMotion}
          initial={reduceMotion ? false : { opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={reduceMotion ? undefined : { opacity: 0 }}
          transition={PRESENCE_LIST_TRANSITION}
          className="space-y-0.5"
        >
          {(!collapsed || isMobile) && (
            <div
              className={cn(
                'mb-1.5 flex items-center justify-between gap-2 px-2.5',
                isMobile && 'px-3',
              )}
            >
              <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60">
                {label}
              </p>
              <UserCategoryCount count={users.length} />
            </div>
          )}
          <AnimatePresence initial={false} mode="popLayout">
            {users.map((user) => (
              <OnlineUserRow
                key={user.id}
                user={user}
                presence={presence}
                collapsed={collapsed}
                isMobile={isMobile}
              />
            ))}
          </AnimatePresence>
        </motion.div>
      ) : null}
    </AnimatePresence>
  )
}

function OnlineUsersPresenceCredits({
  collapsed,
  isMobile = false,
}: {
  collapsed: boolean
  isMobile?: boolean
}) {
  if (collapsed && !isMobile) return null

  return (
    <div
      className={cn(
        'shrink-0 border-t border-border bg-background px-3 py-2.5',
        isMobile && 'px-4',
      )}
    >
      <p className="text-center text-[10px] leading-relaxed text-muted-foreground/60">
        Realtime powered by{' '}
        <a
          href={APPWRITE_PRESENCES_DOCS_URL}
          target="_blank"
          rel="noopener noreferrer"
          className="font-medium text-muted-foreground/75 underline-offset-4 transition-colors hover:text-muted-foreground hover:underline"
        >
          Appwrite Presences
        </a>
      </p>
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
  const reduceMotion = useReducedMotion()
  const hasUsers =
    event.onlineUsers.length > 0 || event.recentlyOnlineUsers.length > 0

  return (
    <AnimatePresence initial={false} mode="wait">
      {showPanel && !hasUsers ? (
        <motion.p
          key="empty"
          initial={reduceMotion ? false : { opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={reduceMotion ? undefined : { opacity: 0 }}
          transition={PRESENCE_LIST_TRANSITION}
          className={cn(
            'px-2.5 text-[13px] text-muted-foreground',
            isMobile && 'px-0',
            collapsed && !isMobile && 'text-center text-[12px]',
          )}
        >
          {collapsed && !isMobile ? '…' : 'No one else online yet. You are connected.'}
        </motion.p>
      ) : (
        <motion.div
          key="lists"
          initial={reduceMotion ? false : { opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={reduceMotion ? undefined : { opacity: 0 }}
          transition={PRESENCE_LIST_TRANSITION}
          className="space-y-6"
        >
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
        </motion.div>
      )}
    </AnimatePresence>
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
    <TooltipProvider delayDuration={200} skipDelayDuration={0}>
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
          <div className="flex min-h-0 flex-1 flex-col">
            <nav
              className="min-h-0 flex-1 overflow-y-auto px-3 py-4"
              role="navigation"
              aria-label="Online participants"
            >
              <OnlineUsersNavContent event={event} collapsed={collapsed} showPanel={showPanel} />
            </nav>

            {showPanel ? (
              <OnlineUsersPresenceCredits collapsed={collapsed} />
            ) : null}
          </div>

          {showPanel ? (
            <div className="shrink-0 border-t border-border bg-muted/20">
              <InitPresenceStatusControl collapsed={collapsed} />
            </div>
          ) : null}
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

        <div className="flex min-h-0 flex-1 flex-col">
          <nav
            className="min-h-0 flex-1 overflow-y-auto px-4 py-4"
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

          {showPanel ? <OnlineUsersPresenceCredits isMobile /> : null}
        </div>

        {showPanel ? (
          <div className="shrink-0 border-t border-border bg-muted/20 px-4 py-3">
            <InitPresenceStatusControl isMobile />
          </div>
        ) : null}
      </aside>
    </TooltipProvider>
  )
}
