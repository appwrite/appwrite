import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { UserRound } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { useDocumentVisible } from '@/hooks/use-document-visible'
import { AnimatedCounter } from '@/components/global/shared/AnimatedCounter'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  ANALYTICS_LIVE_POLL_INTERVAL_MS,
  ANALYTICS_LIVE_WINDOW_MINUTES,
  useAnalyticsLivePages,
  useAnalyticsLiveUsers,
  useAnalyticsLiveVisitors,
} from '@/lib/react-query/hooks'
import { PhotoAvatar } from '@/components/global/shared/Avatar'
import {
  analyticsUserHref,
  analyticsUserLabel,
  useAnalyticsUserDirectory,
  type AnalyticsUser,
} from '@/lib/analytics/user-directory'
import { formatNumber } from './format'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'

/** Avatars on the header pill before the rest fold into the count. */
const PILL_AVATARS = 3
/** Signed-in users listed in the popover. */
const POPOVER_USERS = 5

/** A live user's photo, or a neutral person for an ID with no project user. */
function LiveUserAvatar({
  projectId,
  userId,
  user,
  className,
}: {
  projectId: string
  userId: string
  user: AnalyticsUser | undefined
  className?: string
}) {
  if (!user) {
    return (
      <span
        className={cn(
          'flex shrink-0 items-center justify-center rounded-full bg-muted',
          className,
        )}
        aria-hidden
      >
        <UserRound className="h-3 w-3 text-muted-foreground" />
      </span>
    )
  }
  return (
    <PhotoAvatar
      projectId={projectId}
      userId={userId}
      name={analyticsUserLabel(userId, user)}
      size="xs"
      className={cn('shrink-0', className)}
    />
  )
}

/**
 * Overlapping avatars for the people signed in right now, drawn inside the
 * pill (a button), so they're decoration: the popover lists and links them.
 */
function LiveUserStack({
  projectId,
  userIds,
  lookup,
}: {
  projectId: string
  userIds: string[]
  lookup: (userId: string) => AnalyticsUser | undefined
}) {
  if (userIds.length === 0) return null
  const visible = userIds.slice(0, PILL_AVATARS)
  return (
    <span className="flex items-center" aria-hidden>
      {visible.map((userId, index) => (
        <span
          key={userId}
          className={cn(
            'relative rounded-full ring-2 ring-background',
            index > 0 && '-ms-1.5',
          )}
          style={{ zIndex: visible.length - index }}
        >
          <LiveUserAvatar
            projectId={projectId}
            userId={userId}
            user={lookup(userId)}
            className="h-5 w-5 text-[8px]"
          />
        </span>
      ))}
    </span>
  )
}

function LiveDot({ active }: { active: boolean }) {
  return (
    <span className="relative flex h-2 w-2 shrink-0" aria-hidden>
      {active ? (
        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60 motion-reduce:animate-none" />
      ) : null}
      <span
        className={cn(
          'relative inline-flex h-2 w-2 rounded-full',
          active ? 'bg-emerald-500' : 'bg-muted-foreground/40',
        )}
      />
    </span>
  )
}

type LiveVisitorsProps = {
  projectId: string
  propertyId: string
  /** Disabled properties don't ingest, so there is nothing to poll. */
  enabled?: boolean
  /**
   * `pill` (default) is the standalone header control. `inline` is denser
   * for surfaces like the properties list.
   */
  variant?: 'pill' | 'inline'
  /** Mobile properties count users on screens, not visitors on pages. */
  mobile?: boolean
}

const LIVE_TEXT_CLASS = 'text-emerald-700 dark:text-emerald-400'

/**
 * "N online" pill: unique visitors with activity in the last few minutes,
 * re-read on an interval while the tab is visible. Clicking it opens the
 * pages those visitors are on right now.
 */
export function LiveVisitors({
  projectId,
  propertyId,
  enabled = true,
  variant = 'pill',
  mobile = false,
}: LiveVisitorsProps) {
  const t = useT()
  const isDocumentVisible = useDocumentVisible()
  const [open, setOpen] = useState(false)
  const polling = enabled && isDocumentVisible

  const { visitors, users, checkedAt, error } = useAnalyticsLiveVisitors(
    enabled ? projectId : null,
    propertyId,
    polling,
  )
  const signedIn = users ?? 0
  // Who they are: only while someone identified is live, and for the compact
  // list variant only once the popover is open.
  const { users: liveUsers, isLoading: usersLoading } = useAnalyticsLiveUsers(
    projectId,
    propertyId,
    polling && signedIn > 0 && (variant === 'pill' || open),
  )
  const lookupUser = useAnalyticsUserDirectory(projectId)
  const liveUserIds = signedIn > 0 ? liveUsers.map((row) => row.value) : []
  const { pages: livePages, isLoading: pagesLoading } = useAnalyticsLivePages(
    projectId,
    propertyId,
    open && polling,
  )
  const pages = livePages.filter((page) => isKnownBreakdownValue(page.value))

  const count = visitors ?? 0
  const hasValue = visitors !== undefined && !error
  const isLive = hasValue && count > 0
  const maxPageVisitors = pages.reduce(
    (max, page) => Math.max(max, page.visitors),
    0,
  )

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        {variant === 'inline' ? (
          <button
            type="button"
            className={cn(
              'group/live inline-flex h-6 shrink-0 items-center gap-1.5 rounded-md px-1.5 -mx-1.5 text-[12px] transition-colors',
              'hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              open && 'bg-muted/60',
              isLive ? LIVE_TEXT_CLASS : 'text-muted-foreground',
            )}
            aria-label={t(mobile ? 'Users online now' : 'Visitors online now')}
          >
            {hasValue ? (
              <AnimatedCounter
                value={count}
                className="font-medium tabular-nums"
              />
            ) : (
              <span>-</span>
            )}
            <span>{t('online')}</span>
          </button>
        ) : (
          <button
            type="button"
            className={cn(
              'inline-flex h-7 shrink-0 items-center gap-1.5 text-[12px] font-medium transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              isLive
                ? `${LIVE_TEXT_CLASS} hover:text-emerald-800 dark:hover:text-emerald-300`
                : 'text-muted-foreground hover:text-foreground',
            )}
            aria-label={t(mobile ? 'Users online now' : 'Visitors online now')}
          >
            <LiveUserStack
              projectId={projectId}
              userIds={liveUserIds}
              lookup={lookupUser}
            />
            {hasValue ? (
              <AnimatedCounter
                value={count}
                className="font-semibold tabular-nums"
              />
            ) : (
              <span className="font-semibold">-</span>
            )}
            <span className="hidden sm:inline">{t('online')}</span>
          </button>
        )}
      </PopoverTrigger>
      <PopoverContent align="end" sideOffset={6} className="w-[300px] p-0">
        <div className="border-b border-border px-3 py-2.5">
          <div className="flex items-center gap-2">
            <LiveDot active={isLive && polling} />
            <p className="text-[13px] font-semibold text-foreground">
              {hasValue ? formatNumber(count) : '-'}{' '}
              {count === 1
                ? t(mobile ? 'user online' : 'visitor online')
                : t(mobile ? 'users online' : 'visitors online')}
            </p>
            {hasValue && signedIn > 0 ? (
              <span className="ms-auto shrink-0 text-[11px] text-muted-foreground">
                <span className="font-medium tabular-nums text-foreground">
                  {formatNumber(signedIn)}
                </span>{' '}
                {t('signed in')}
              </span>
            ) : null}
          </div>
          <p className="mt-1 text-[11px] leading-snug text-muted-foreground">
            {t(
              mobile
                ? 'Unique users active in the last'
                : 'Unique visitors active in the last',
            )}{' '}
            {ANALYTICS_LIVE_WINDOW_MINUTES} {t('minutes')}.{' '}
            {polling
              ? `${t('Updates every')} ${ANALYTICS_LIVE_POLL_INTERVAL_MS / 1000}s.`
              : t('Paused while this tab is hidden.')}
          </p>
          {error ? (
            <p className="mt-1 text-[11px] text-amber-600 dark:text-amber-400">
              {error instanceof Error
                ? error.message
                : t(
                    mobile
                      ? 'Could not load live users'
                      : 'Could not load live visitors',
                  )}
            </p>
          ) : null}
        </div>
        {hasValue && signedIn > 0 ? (
          <div className="border-b border-border px-3 py-2.5">
            <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Signed in now')}
            </p>
            {usersLoading && liveUserIds.length === 0 ? (
              <div className="space-y-1.5" aria-hidden>
                {Array.from({ length: Math.min(signedIn, 3) }).map(
                  (_, index) => (
                    <div
                      key={index}
                      className="h-6 animate-pulse rounded bg-muted/50"
                    />
                  ),
                )}
              </div>
            ) : (
              <div className="space-y-0.5">
                {liveUserIds.slice(0, POPOVER_USERS).map((userId) => {
                  const user = lookupUser(userId)
                  const label = analyticsUserLabel(userId, user)
                  const content = (
                    <>
                      <LiveUserAvatar
                        projectId={projectId}
                        userId={userId}
                        user={user}
                        className="h-4 w-4 text-[7px]"
                      />
                      <span
                        className={cn(
                          'min-w-0 flex-1 truncate text-[12px] text-foreground',
                          !user && 'font-mono text-[11px]',
                        )}
                        title={label}
                      >
                        {label}
                      </span>
                    </>
                  )
                  // Project users open in Auth; other IDs have nowhere to go.
                  return user ? (
                    <Link
                      key={userId}
                      to={analyticsUserHref(projectId, userId)}
                      onClick={() => setOpen(false)}
                      className="flex h-6 items-center gap-2 rounded px-1.5 transition-colors hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      {content}
                    </Link>
                  ) : (
                    <div
                      key={userId}
                      className="flex h-6 items-center gap-2 px-1.5"
                    >
                      {content}
                    </div>
                  )
                })}
                {signedIn > Math.min(liveUserIds.length, POPOVER_USERS) ? (
                  <p className="px-1.5 pt-1 text-[11px] text-muted-foreground">
                    {t('and {count} more').replace(
                      '{count}',
                      formatNumber(
                        signedIn - Math.min(liveUserIds.length, POPOVER_USERS),
                      ),
                    )}
                  </p>
                ) : null}
              </div>
            )}
          </div>
        ) : null}
        <div className="px-3 py-2.5">
          <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t(mobile ? 'Active screens' : 'Active pages')}
          </p>
          {pagesLoading && pages.length === 0 ? (
            <div className="space-y-1.5" aria-hidden>
              {Array.from({ length: 3 }).map((_, index) => (
                <div
                  key={index}
                  className="h-6 animate-pulse rounded bg-muted/50"
                />
              ))}
            </div>
          ) : pages.length === 0 ? (
            <p className="py-2 text-[12px] text-muted-foreground">
              {t(
                mobile
                  ? 'No one is using the app right now.'
                  : 'No one is browsing right now.',
              )}
            </p>
          ) : (
            <div className="space-y-0.5">
              {pages.map((page, index) => (
                <div
                  key={`${page.value}-${index}`}
                  className="relative flex h-6 items-center gap-2 overflow-hidden rounded px-1.5"
                >
                  <span
                    className="absolute inset-y-0 start-0 rounded bg-emerald-500/10"
                    style={{
                      width: `${maxPageVisitors > 0 ? (page.visitors / maxPageVisitors) * 100 : 0}%`,
                    }}
                    aria-hidden
                  />
                  <span className="relative min-w-0 flex-1 truncate font-mono text-[11px] text-foreground">
                    {page.value || t('Unknown')}
                  </span>
                  <span className="relative shrink-0 text-[11px] font-medium tabular-nums text-foreground">
                    {formatNumber(page.visitors)}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
        {checkedAt ? (
          <div className="border-t border-border px-3 py-1.5 text-[10px] text-muted-foreground">
            {t('Last checked')}{' '}
            {new Date(checkedAt).toLocaleTimeString(undefined, {
              hour: '2-digit',
              minute: '2-digit',
              second: '2-digit',
            })}
          </div>
        ) : null}
      </PopoverContent>
    </Popover>
  )
}
