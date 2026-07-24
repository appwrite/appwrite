import { Link } from '@tanstack/react-router'
import type {
  LaunchEvent,
  LaunchEventOnlineUser,
  LaunchEventUserPresence,
} from '@/lib/init/types'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Skeleton } from '@/components/ui/skeleton'
import { useInitPresence } from '@/lib/init/init-presence-context'
import { parseInitReactingActivity, formatInitPresenceActivityDisplay } from '@/lib/init/reactions'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  SECONDARY_SIDEBAR_NAV_LINK_COLLAPSED_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
} from '@/lib/layout/secondary-sidebar-nav'
import {
  OFFCANVAS_START_CLOSED,
  SIDEBAR_EDGE_TOGGLE_OVERFLOW,
} from '@/lib/layout/offcanvas-classes'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, HelpCircle, LogIn, X } from 'lucide-react'
import { AnimatePresence, motion, useReducedMotion } from 'motion/react'
import { useMemo, useState, type CSSProperties } from 'react'
import { useInitGiveawayRaffleContext } from './init-giveaway-raffle-context'
import { InitPresenceReactionSpark } from './InitPresenceReactionSpark'
import { InitPresenceReactions } from './InitPresenceReactions'
import { InitPresenceStatusControl } from './InitPresenceStatusControl'
import { InitPresenceThemeBar } from './InitPresenceThemeBar'
import { OnlineUserReactionBadgeSlot } from './OnlineUserReactionBadge'

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
const PRESENCE_ACTIVITY_ENTER_TRANSITION = { duration: 0.22, ease: PRESENCE_LIST_EASE }
const PRESENCE_ACTIVITY_EXIT_TRANSITION = { duration: 0.14, ease: PRESENCE_LIST_EASE }
const PRESENCE_RING_PULSE_TRANSITION = { duration: 0.42, ease: PRESENCE_LIST_EASE }

function buildPresenceStatusKey(
  user: LaunchEventOnlineUser,
  presence: LaunchEventUserPresence,
): string {
  return `${presence}:${user.isLive ? 'live' : 'idle'}`
}

/** Readable popover-style tooltip for collapsed sidebar rows (not inverted xs pills). */
const ONLINE_USER_TOOLTIP_CLASS =
  'max-w-[min(280px,calc(100dvw-5rem))] border border-border bg-popover px-3 py-2.5 text-popover-foreground shadow-md [&_svg]:!hidden [&_.reaction-badge_svg]:!inline-block'

const ONLINE_USERS_LIST_CLASS = 'cursor-default select-none'

const RAFFLE_WINNER_SURFACE_CLASS =
  'bg-[color-mix(in_srgb,var(--brand-cta)_14%,var(--card))]'
const RAFFLE_WINNER_AVATAR_CLASS =
  'bg-[color-mix(in_srgb,var(--brand-cta)_14%,var(--card))] text-foreground'
const PRESENCE_AVATAR_SHELL_CLASS =
  'relative z-[1] inline-flex shrink-0 rounded-full [transform:translateZ(0)] [backface-visibility:hidden]'

function sortUsersWithRaffleWinner(
  users: LaunchEventOnlineUser[],
  raffleWinnerId: string | null,
) {
  if (!raffleWinnerId) return users

  const winner = users.find((user) => user.id === raffleWinnerId)
  if (!winner) return users

  return [winner, ...users.filter((user) => user.id !== raffleWinnerId)]
}

function RaffleWinnerSparkles() {
  const reduceMotion = useReducedMotion()
  const sparkAnchors = useMemo(
    () => [
      { id: 'a', left: '10%', top: '28%', sparkIndex: 0 },
      { id: 'b', left: '24%', top: '68%', sparkIndex: 2 },
      { id: 'c', left: '38%', top: '34%', sparkIndex: 4 },
      { id: 'd', left: '52%', top: '62%', sparkIndex: 1 },
      { id: 'e', left: '66%', top: '30%', sparkIndex: 3 },
      { id: 'f', left: '80%', top: '70%', sparkIndex: 5 },
      { id: 'g', left: '92%', top: '38%', sparkIndex: 0 },
      { id: 'h', left: '46%', top: '48%', sparkIndex: 2 },
    ],
    [],
  )

  if (reduceMotion) return null

  return (
    <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-md" aria-hidden>
      {sparkAnchors.map((anchor) => (
        <span
          key={anchor.id}
          className="absolute size-7 -translate-x-1/2 -translate-y-1/2"
          style={{ left: anchor.left, top: anchor.top }}
        >
          <InitPresenceReactionSpark
            index={anchor.sparkIndex}
            animationKey={`winner-${anchor.id}`}
          />
        </span>
      ))}
    </div>
  )
}

function OnlineUserTooltipDetails({
  name,
  activity,
  selfUserId,
  userId,
  reactionPulse,
}: {
  name: string
  activity: string
  selfUserId?: string
  userId?: string
  reactionPulse?: number
}) {
  return (
    <div className="space-y-1 text-start">
      <OnlineUserName
        name={name}
        activity={activity}
        reactionPulse={reactionPulse}
        isSelf={Boolean(selfUserId && userId === selfUserId)}
      />
      <OnlineUserActivity activity={activity} className="text-muted-foreground/70" />
    </div>
  )
}

function OnlineUserName({
  name,
  activity,
  reactionPulse,
  isSelf = false,
}: {
  name: string
  activity: string
  reactionPulse?: number
  isSelf?: boolean
}) {
  const reaction = parseInitReactingActivity(activity)
  const animationKey = reaction
    ? isSelf && reactionPulse !== undefined
      ? `${reaction.id}-${reactionPulse}`
      : reaction.id
    : null

  return (
    <p className="flex min-h-[18px] min-w-0 items-center gap-2 text-[13px] font-medium leading-snug text-foreground">
      <span className="min-w-0 flex-1 truncate">{name}</span>
      <span className="inline-flex size-5 shrink-0 items-center justify-center">
        <OnlineUserReactionBadgeSlot reaction={reaction} animationKey={animationKey} />
      </span>
    </p>
  )
}

function PresenceAvatar({
  user,
  presence,
  size = 'sm',
  isRaffleWinner = false,
}: {
  user: LaunchEventOnlineUser
  presence: LaunchEventUserPresence
  size?: AvatarSize
  isRaffleWinner?: boolean
}) {
  const reduceMotion = useReducedMotion()
  const statusKey = buildPresenceStatusKey(user, presence)

  return (
    <motion.span
      key={isRaffleWinner ? `winner-${statusKey}` : statusKey}
      className={PRESENCE_AVATAR_SHELL_CLASS}
      animate={reduceMotion ? undefined : { scale: [1, 1.14, 1] }}
      transition={PRESENCE_RING_PULSE_TRANSITION}
      aria-hidden
    >
      <InitialsAvatar
        name={user.name}
        size={size}
        className={cn('rounded-full', isRaffleWinner && RAFFLE_WINNER_AVATAR_CLASS)}
      />
    </motion.span>
  )
}

const ONLINE_USER_ACTIVITY_CLASS =
  'truncate text-[12px] font-normal leading-[16px] text-muted-foreground'

function OnlineUserActivity({
  activity,
  className,
}: {
  activity: string
  className?: string
}) {
  const reduceMotion = useReducedMotion()
  const displayActivity = formatInitPresenceActivityDisplay(activity)
  const textClassName = cn(ONLINE_USER_ACTIVITY_CLASS, className)

  if (reduceMotion) {
    return (
      <p className={cn('h-4', textClassName)} title={displayActivity}>
        {displayActivity}
      </p>
    )
  }

  return (
    <div
      className="relative h-4 overflow-hidden"
      aria-live="polite"
      aria-atomic="true"
    >
      <AnimatePresence initial={false}>
        <motion.p
          key={displayActivity}
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{
            opacity: 0,
            transition: PRESENCE_ACTIVITY_EXIT_TRANSITION,
          }}
          transition={PRESENCE_ACTIVITY_ENTER_TRANSITION}
          className={cn('absolute inset-x-0 top-0', textClassName)}
          title={displayActivity}
        >
          {displayActivity}
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
  selfUserId,
  reactionPulse = 0,
  isRaffleWinner = false,
}: {
  user: LaunchEventOnlineUser
  presence: LaunchEventUserPresence
  collapsed: boolean
  isMobile?: boolean
  selfUserId?: string
  reactionPulse?: number
  isRaffleWinner?: boolean
}) {
  const reduceMotion = useReducedMotion()
  const avatarSize: AvatarSize = isMobile ? 'md' : 'sm'
  const isSelf = Boolean(selfUserId && user.id === selfUserId)
  const displayActivity = formatInitPresenceActivityDisplay(user.activity)

  const row = (
    <div
      className={cn(
        'relative w-full rounded-md px-2.5 py-1.5 text-[13px] font-medium transition-colors duration-300',
        ONLINE_USERS_LIST_CLASS,
        isRaffleWinner
          ? cn(RAFFLE_WINNER_SURFACE_CLASS, 'text-foreground')
          : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
        collapsed && !isMobile
          ? SECONDARY_SIDEBAR_NAV_LINK_COLLAPSED_CLASS
          : SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
        isMobile && 'gap-x-3 px-3 py-2.5 text-[14px]',
      )}
    >
      {isRaffleWinner ? <RaffleWinnerSparkles /> : null}
      <PresenceAvatar
        user={user}
        presence={presence}
        size={avatarSize}
        isRaffleWinner={isRaffleWinner}
      />
      {(!collapsed || isMobile) && (
        <div className="relative z-[1] min-w-0 flex-1 text-start">
          <OnlineUserName
            name={user.name}
            activity={user.activity}
            reactionPulse={reactionPulse}
            isSelf={isSelf}
          />
          <div className="min-w-0 flex-1">
            <OnlineUserActivity
              activity={isRaffleWinner ? 'Winner!' : user.activity}
              className={isRaffleWinner ? 'text-muted-foreground' : undefined}
            />
          </div>
        </div>
      )}
    </div>
  )

  const animatedRow = (
    <motion.div
      layout={!reduceMotion}
      initial={reduceMotion ? false : { opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={reduceMotion ? undefined : { opacity: 0 }}
      transition={{
        ...PRESENCE_LIST_TRANSITION,
        layout: reduceMotion
          ? undefined
          : { type: 'spring', stiffness: 420, damping: 34, mass: 0.85 },
      }}
    >
      {collapsed && !isMobile ? (
        <Tooltip delayDuration={200}>
          <TooltipTrigger asChild>
            <button
              type="button"
              className={cn(
                'relative flex w-full cursor-default select-none items-center justify-center rounded-md px-0 py-1.5',
                'transition-colors duration-300',
                isRaffleWinner ? RAFFLE_WINNER_SURFACE_CLASS : 'hover:bg-accent/50',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
              )}
              aria-label={`${user.name}. ${isRaffleWinner ? 'Winner!' : displayActivity}`}
            >
              {isRaffleWinner ? <RaffleWinnerSparkles /> : null}
              <PresenceAvatar
        user={user}
        presence={presence}
        size={avatarSize}
        isRaffleWinner={isRaffleWinner}
      />
            </button>
          </TooltipTrigger>
          <TooltipContent
            side="right"
            sideOffset={12}
            className={ONLINE_USER_TOOLTIP_CLASS}
          >
            <OnlineUserTooltipDetails
              name={user.name}
              activity={user.activity}
              userId={user.id}
              selfUserId={selfUserId}
              reactionPulse={reactionPulse}
            />
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
      transition={PRESENCE_ACTIVITY_ENTER_TRANSITION}
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
  selfUserId,
  reactionPulse,
  raffleWinnerId = null,
  infoTooltip,
}: {
  label: string
  users: LaunchEventOnlineUser[]
  presence: LaunchEventUserPresence
  collapsed: boolean
  isMobile?: boolean
  selfUserId?: string
  reactionPulse?: number
  raffleWinnerId?: string | null
  infoTooltip?: string
}) {
  const t = useT()
  const reduceMotion = useReducedMotion()
  const sortedUsers = useMemo(
    () => sortUsersWithRaffleWinner(users, raffleWinnerId),
    [raffleWinnerId, users],
  )

  return (
    <AnimatePresence initial={false}>
      {users.length > 0 ? (
        <motion.div
          key={label}
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
              <div className="flex min-w-0 items-center gap-1">
                <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60">
                  {t(label)}
                </p>
                {infoTooltip ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <button
                        type="button"
                        className="inline-flex shrink-0 items-center justify-center rounded-sm text-muted-foreground/60 transition-colors hover:text-muted-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label={t(infoTooltip)}
                      >
                        <HelpCircle className="size-3" />
                      </button>
                    </TooltipTrigger>
                    <TooltipContent side="top" sideOffset={4} className="max-w-[220px] text-[12px]">
                      {t(infoTooltip)}
                    </TooltipContent>
                  </Tooltip>
                ) : null}
              </div>
              <UserCategoryCount count={users.length} />
            </div>
          )}
          <AnimatePresence initial={false} mode="popLayout">
            {sortedUsers.map((user) => (
              <OnlineUserRow
                key={user.id}
                user={user}
                presence={presence}
                collapsed={collapsed}
                isMobile={isMobile}
                selfUserId={selfUserId}
                reactionPulse={reactionPulse}
                isRaffleWinner={Boolean(raffleWinnerId && user.id === raffleWinnerId)}
              />
            ))}
          </AnimatePresence>
        </motion.div>
      ) : null}
    </AnimatePresence>
  )
}

function SkeletonPulse({
  className,
  style,
}: {
  className?: string
  style?: CSSProperties
}) {
  return (
    <Skeleton
      className={cn('bg-muted/80 dark:bg-muted/40', className)}
      style={style}
    />
  )
}

function OnlineUserRowSkeleton({
  collapsed,
  isMobile = false,
  nameWidth,
  activityWidth,
  index = 0,
}: {
  collapsed: boolean
  isMobile?: boolean
  nameWidth: string
  activityWidth: string
  index?: number
}) {
  const reduceMotion = useReducedMotion()
  const avatarSize = isMobile ? 'size-10' : 'size-8'
  const staggerMs = index * 55

  const content =
    collapsed && !isMobile ? (
      <div className="flex justify-center py-1.5">
        <SkeletonPulse
          className={cn('rounded-full', avatarSize)}
          style={reduceMotion ? undefined : { animationDelay: `${staggerMs}ms` }}
        />
      </div>
    ) : (
      <div
        className={cn(
          'flex items-center gap-2.5 rounded-md px-2.5 py-1.5',
          isMobile && 'gap-3 px-3 py-2.5',
        )}
      >
        <SkeletonPulse
          className={cn('shrink-0 rounded-full ring-2 ring-background', avatarSize)}
          style={reduceMotion ? undefined : { animationDelay: `${staggerMs}ms` }}
        />
        <div className="min-w-0 flex-1 space-y-1.5">
          <SkeletonPulse
            className={cn('h-3.5', nameWidth)}
            style={
              reduceMotion ? undefined : { animationDelay: `${staggerMs + 40}ms` }
            }
          />
          <SkeletonPulse
            className={cn('h-3', activityWidth)}
            style={
              reduceMotion ? undefined : { animationDelay: `${staggerMs + 80}ms` }
            }
          />
        </div>
      </div>
    )

  if (reduceMotion) return content

  return <div>{content}</div>
}

function UserCategorySkeleton({
  labelWidth,
  rowCount,
  collapsed,
  isMobile = false,
  rowOffset = 0,
}: {
  labelWidth: string
  rowCount: number
  collapsed: boolean
  isMobile?: boolean
  rowOffset?: number
}) {
  const nameWidths = ['w-[72%]', 'w-[58%]', 'w-[64%]', 'w-[52%]'] as const
  const activityWidths = ['w-[88%]', 'w-[76%]', 'w-[82%]', 'w-[70%]'] as const

  return (
    <div className="space-y-0.5">
      {(!collapsed || isMobile) && (
        <div
          className={cn(
            'mb-1.5 flex items-center justify-between gap-2 px-2.5',
            isMobile && 'px-3',
          )}
        >
          <SkeletonPulse className={cn('h-2.5', labelWidth)} />
          <SkeletonPulse className="h-2.5 w-4 shrink-0 rounded-sm" />
        </div>
      )}
      {Array.from({ length: rowCount }, (_, index) => (
        <OnlineUserRowSkeleton
          key={index}
          collapsed={collapsed}
          isMobile={isMobile}
          nameWidth={nameWidths[(rowOffset + index) % nameWidths.length]}
          activityWidth={activityWidths[(rowOffset + index) % activityWidths.length]}
          index={rowOffset + index}
        />
      ))}
    </div>
  )
}

function OnlineUsersListSkeletonView({
  collapsed,
  isMobile = false,
}: {
  collapsed: boolean
  isMobile?: boolean
}) {
  return (
    <div
      aria-busy="true"
      aria-label="Loading online participants"
    >
      {collapsed && !isMobile ? (
        <div className="space-y-0.5">
          {Array.from({ length: 5 }, (_, index) => (
            <OnlineUserRowSkeleton key={index} collapsed index={index} />
          ))}
        </div>
      ) : (
        <div className="space-y-6">
          <UserCategorySkeleton
            labelWidth="w-16"
            rowCount={4}
            collapsed={collapsed}
            isMobile={isMobile}
            rowOffset={0}
          />
          <UserCategorySkeleton
            labelWidth="w-24"
            rowCount={2}
            collapsed={collapsed}
            isMobile={isMobile}
            rowOffset={4}
          />
        </div>
      )}
      <span className="sr-only">Loading online participants</span>
    </div>
  )
}

const ONLINE_USERS_LIST_MIN_HEIGHT = 'min-h-[240px]'

function OnlineUsersNavContent({
  event,
  collapsed,
  isMobile = false,
  showPanel = false,
  isLoading = false,
  isAuthenticated = false,
  selfUserId,
  reactionPulse,
  raffleWinnerId = null,
}: {
  event: LaunchEvent
  collapsed: boolean
  isMobile?: boolean
  showPanel?: boolean
  isLoading?: boolean
  isAuthenticated?: boolean
  selfUserId?: string
  reactionPulse?: number
  raffleWinnerId?: string | null
}) {
  const t = useT()
  const hasUsers =
    event.onlineUsers.length > 0 || event.recentlyOnlineUsers.length > 0

  const emptyMessage = isAuthenticated
    ? t('No one else online yet. You are connected.')
    : t('Sign in to join the event and see who is online.')

  return (
    <div className={cn('relative', ONLINE_USERS_LIST_MIN_HEIGHT)}>
      {showPanel && isLoading ? (
        <OnlineUsersListSkeletonView collapsed={collapsed} isMobile={isMobile} />
      ) : showPanel && !hasUsers ? (
        <p
          className={cn(
            'px-2.5 text-[13px] text-muted-foreground',
            isMobile && 'px-0',
            collapsed && !isMobile && 'text-center text-[12px]',
          )}
        >
          {collapsed && !isMobile ? '…' : emptyMessage}
        </p>
      ) : (
        <div className={cn('space-y-6', ONLINE_USERS_LIST_CLASS)}>
          <UserCategory
            label="Online now"
            users={event.onlineUsers}
            presence="online"
            collapsed={collapsed}
            isMobile={isMobile}
            selfUserId={selfUserId}
            reactionPulse={reactionPulse}
            raffleWinnerId={raffleWinnerId}
            infoTooltip="This feature is powered by Appwrite Realtime and Appwrite Presences."
          />
          <UserCategory
            label="Recently online"
            users={event.recentlyOnlineUsers}
            presence="recent"
            collapsed={collapsed}
            isMobile={isMobile}
            selfUserId={selfUserId}
            reactionPulse={reactionPulse}
          />
        </div>
      )}
    </div>
  )
}

function OnlineUsersLoginCta({
  collapsed = false,
  isMobile = false,
}: {
  collapsed?: boolean
  isMobile?: boolean
}) {
  const t = useT()

  if (collapsed && !isMobile) {
    return (
      <div className="flex justify-center py-2">
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="brandCta"
              size="icon"
              className="size-9"
              asChild
            >
              <Link
                to="/sign-in"
                search={{ redirect: '/init' }}
                aria-label={t('Sign in to join the event')}
              >
                <LogIn className="size-4" aria-hidden />
              </Link>
            </Button>
          </TooltipTrigger>
          <TooltipContent side="right" className="text-[12px]">
            {t('Sign in to join the event')}
          </TooltipContent>
        </Tooltip>
      </div>
    )
  }

  return (
    <div className={cn('space-y-2 px-2.5 py-3', isMobile && 'px-0 py-0')}>
      <p className="text-[12px] leading-snug text-muted-foreground">
        {t('Sign in to join the event')}
      </p>
      <Button variant="brandCta" size="sm" className="h-8 w-full text-[12px]" asChild>
        <Link to="/sign-in" search={{ redirect: '/init' }}>
          {t('Sign in')}
        </Link>
      </Button>
    </div>
  )
}

export function hasOnlineUsersNav(
  event: LaunchEvent,
  options?: { presenceEnabled?: boolean },
) {
  if (options?.presenceEnabled) {
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
  const [reactionPulse, setReactionPulse] = useState(0)
  const { data: account } = useQuery(consoleAccountQueryOptions())
  const { isReady: isPresenceReady, onlineThemeCounts } = useInitPresence()
  const raffle = useInitGiveawayRaffleContext()
  const isAuthenticated = Boolean(account)
  const isLoadingPresence = showPanel && isAuthenticated && !isPresenceReady
  const selfUserId = account?.$id

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
            'flex h-full w-full flex-col overflow-hidden border-e border-border bg-background',
            '[transform:translateZ(0)] [backface-visibility:hidden]',
          )}
        >
          <div className="flex min-h-0 flex-1 flex-col">
            <nav
              className={cn('min-h-0 flex-1 overflow-y-auto px-3 py-4', ONLINE_USERS_LIST_CLASS)}
              role="navigation"
              aria-label="Online participants"
            >
              <OnlineUsersNavContent
                event={event}
                collapsed={collapsed}
                showPanel={showPanel}
                isLoading={isLoadingPresence}
                isAuthenticated={isAuthenticated}
                selfUserId={selfUserId}
                reactionPulse={reactionPulse}
                raffleWinnerId={raffle?.raffleWinnerId ?? null}
              />
            </nav>

            {showPanel && isAuthenticated ? (
              <InitPresenceReactions
                eventId={event.id}
                collapsed={collapsed}
                onReactionPulse={() => setReactionPulse((count) => count + 1)}
              />
            ) : null}

            {showPanel && isAuthenticated ? (
              <InitPresenceThemeBar
                light={onlineThemeCounts.light}
                dark={onlineThemeCounts.dark}
                collapsed={collapsed}
                reserveSpace={!isPresenceReady}
              />
            ) : null}
          </div>

          {showPanel ? (
            <div className="shrink-0 border-t border-border bg-muted/20">
              {isAuthenticated ? (
                <InitPresenceStatusControl collapsed={collapsed} />
              ) : (
                <OnlineUsersLoginCta collapsed={collapsed} />
              )}
            </div>
          ) : null}
        </aside>

        <button
          type="button"
          onClick={() => setCollapsed(!collapsed)}
          className={cn(
            'absolute end-0 top-1/2 z-10 flex h-6 w-6 shrink-0 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md border border-border bg-card text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            SIDEBAR_EDGE_TOGGLE_OVERFLOW,
          )}
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
          'fixed start-0 top-0 z-[130] flex h-[100dvh] max-h-[100dvh] w-[280px] flex-col overflow-hidden border-e border-border bg-background',
          'transition-transform duration-200 ease-out [backface-visibility:hidden]',
          '@[1024px]:hidden',
          mobileOpen ? 'translate-x-0' : OFFCANVAS_START_CLOSED,
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
            className={cn('min-h-0 flex-1 overflow-y-auto px-4 py-4', ONLINE_USERS_LIST_CLASS)}
            role="navigation"
            aria-label="Mobile online participants"
          >
            <OnlineUsersNavContent
              event={event}
              collapsed={false}
              isMobile
              showPanel={showPanel}
              isLoading={isLoadingPresence}
              isAuthenticated={isAuthenticated}
              selfUserId={selfUserId}
              reactionPulse={reactionPulse}
              raffleWinnerId={raffle?.raffleWinnerId ?? null}
            />
          </nav>

          {showPanel && isAuthenticated ? (
            <InitPresenceReactions
              eventId={event.id}
              isMobile
              onReactionPulse={() => setReactionPulse((count) => count + 1)}
            />
          ) : null}

          {showPanel && isAuthenticated ? (
            <InitPresenceThemeBar
              light={onlineThemeCounts.light}
              dark={onlineThemeCounts.dark}
              isMobile
              reserveSpace={!isPresenceReady}
            />
          ) : null}
        </div>

        {showPanel ? (
          <div className="shrink-0 border-t border-border bg-muted/20 px-4 py-3">
            {isAuthenticated ? (
              <InitPresenceStatusControl isMobile />
            ) : (
              <OnlineUsersLoginCta isMobile />
            )}
          </div>
        ) : null}
      </aside>
    </TooltipProvider>
  )
}
