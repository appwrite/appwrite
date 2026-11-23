import { lazy, Suspense, useMemo, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import {
  AlertCircle,
  AlertTriangle,
  ArrowLeft,
  Bell,
  BellOff,
  BellRing,
  CheckCheck,
  Circle,
  Clock,
  ExternalLink,
  Info,
  Loader2,
  MailOpen,
  MoreHorizontal,
  Settings,
} from 'lucide-react'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { cn } from '@/lib/utils'
import { getNotificationNavigateTarget } from '@/lib/notifications/notification-links'
import {
  EVERY_ORGANIZATION,
  SNOOZE_DURATIONS,
  snoozeOf,
  withMutedProject,
  withoutSnooze,
  withSnooze,
} from '@/lib/notifications/beep-prefs'
import {
  useBeepPrefs,
  useConsoleNotifications,
  useMarkAllConsoleNotificationsRead,
  useUpdateConsoleNotificationRead,
} from '@/lib/react-query/hooks/notifications'
import type { ConsoleAccountCache } from '@/lib/react-query/hooks/auth'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { formatUntil, NotificationSettings } from './NotificationSettings'

// The changelog ships every entry's text, so it loads only when the tab opens.
const NotificationUpdates = lazy(() =>
  import('./NotificationUpdates').then((module) => ({
    default: module.NotificationUpdates,
  })),
)

type Severity = 'error' | 'warning' | 'info'

function severityOf(type: string): Severity {
  const normalized = type.trim().toLowerCase()
  if (normalized === 'error' || normalized === 'critical') return 'error'
  if (normalized === 'warning') return 'warning'
  return 'info'
}

const SEVERITY_ICONS = {
  error: AlertCircle,
  warning: AlertTriangle,
  info: Info,
} as const

const SEVERITY_TONES: Record<Severity, string> = {
  error: 'border-red-500/25 bg-red-500/10 text-red-600 dark:text-red-400',
  warning:
    'border-amber-500/25 bg-amber-500/10 text-amber-600 dark:text-amber-400',
  info: 'border-border bg-background text-muted-foreground',
}

type Day = 'today' | 'yesterday' | 'earlier'

function dayOf(iso: string, now: Date): Day {
  const date = new Date(iso)
  if (date.toDateString() === now.toDateString()) return 'today'
  const yesterday = new Date(now)
  yesterday.setDate(now.getDate() - 1)
  if (date.toDateString() === yesterday.toDateString()) return 'yesterday'
  return 'earlier'
}

const DAY_LABELS: Record<Day, string> = {
  today: 'Today',
  yesterday: 'Yesterday',
  earlier: 'Earlier',
}

function groupByDay(notifications: Models.Notification[]) {
  const now = new Date()
  const groups: { day: Day; items: Models.Notification[] }[] = []
  for (const notification of notifications) {
    const day = dayOf(notification.$createdAt, now)
    const last = groups.at(-1)
    if (last?.day === day) {
      last.items.push(notification)
    } else {
      groups.push({ day, items: [notification] })
    }
  }
  return groups
}

function NotificationRow({
  notification,
  projectMuted,
  onOpen,
  onToggleRead,
  onToggleMuteProject,
}: {
  notification: Models.Notification
  projectMuted: boolean
  onOpen: (notification: Models.Notification) => void
  onToggleRead: (notification: Models.Notification) => void
  onToggleMuteProject: (projectId: string, muted: boolean) => void
}) {
  const t = useT()
  const isUnread = !notification.read
  const severity = severityOf(notification.type)
  const Icon = SEVERITY_ICONS[severity]
  const projectId = notification.projectId?.trim() ?? ''
  const canOpen = getNotificationNavigateTarget(notification) !== null

  return (
    <div
      className={cn(
        'group relative flex gap-3 px-4 py-3 transition-colors hover:bg-accent/60',
        isUnread && 'bg-muted/30',
      )}
    >
      <button
        type="button"
        onClick={() => onOpen(notification)}
        className="absolute inset-0 z-0"
        aria-label={notification.title}
      />
      <div
        className={cn(
          'pointer-events-none relative mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border',
          SEVERITY_TONES[severity],
        )}
      >
        <Icon className="h-4 w-4" aria-hidden />
        {isUnread ? (
          <span
            className="absolute -end-0.5 -top-0.5 size-2 rounded-full bg-primary ring-2 ring-popover"
            aria-hidden
          />
        ) : null}
      </div>
      <div className="pointer-events-none relative min-w-0 flex-1 space-y-1 overflow-hidden">
        <p
          className={cn(
            'break-words pe-7 text-[13px] leading-snug text-foreground',
            isUnread && 'font-semibold',
          )}
        >
          {notification.title}
        </p>
        {notification.body?.trim() ? (
          <p className="line-clamp-2 break-words text-[12px] leading-relaxed text-muted-foreground">
            {notification.body}
          </p>
        ) : null}
        <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
          <DateTooltip
            date={notification.$createdAt}
            live
            className="pointer-events-auto text-[11px] text-muted-foreground"
          />
          {projectMuted ? (
            <span className="inline-flex items-center gap-1">
              <BellOff className="size-3" aria-hidden />
              {t('Project muted')}
            </span>
          ) : null}
        </div>
      </div>
      <DropdownMenu modal={false}>
        <DropdownMenuTrigger asChild>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="absolute end-2 top-2 z-10 h-7 w-7 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100"
            aria-label={t('Notification actions')}
          >
            <MoreHorizontal className="h-4 w-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="z-[10060] w-56">
          {canOpen ? (
            <DropdownMenuItem onSelect={() => onOpen(notification)}>
              <MenuItemContent icon={ExternalLink}>{t('Open')}</MenuItemContent>
            </DropdownMenuItem>
          ) : null}
          <DropdownMenuItem onSelect={() => onToggleRead(notification)}>
            {isUnread ? (
              <MenuItemContent icon={MailOpen}>
                {t('Mark as read')}
              </MenuItemContent>
            ) : (
              <MenuItemContent icon={Circle}>
                {t('Mark as unread')}
              </MenuItemContent>
            )}
          </DropdownMenuItem>
          {projectId ? (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onSelect={() => onToggleMuteProject(projectId, !projectMuted)}
              >
                {projectMuted ? (
                  <MenuItemContent icon={BellRing}>
                    {t('Unmute project pushes')}
                  </MenuItemContent>
                ) : (
                  <MenuItemContent icon={BellOff}>
                    {t('Mute project pushes')}
                  </MenuItemContent>
                )}
              </DropdownMenuItem>
            </>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}

function SnoozeMenu({
  snoozedUntil,
  disabled,
  onSnooze,
  onResume,
}: {
  snoozedUntil: Date | null
  disabled: boolean
  onSnooze: (minutes: number) => void
  onResume: () => void
}) {
  const t = useT()
  return (
    <DropdownMenu modal={false}>
      <Tooltip>
        <TooltipTrigger asChild>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              disabled={disabled}
              className={cn(
                'h-8 w-8 text-muted-foreground',
                snoozedUntil && 'text-primary',
              )}
              aria-label={t('Snooze pushes')}
            >
              <Clock className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
        </TooltipTrigger>
        <TooltipContent className="z-[10060]">
          <p>{t('Snooze pushes')}</p>
        </TooltipContent>
      </Tooltip>
      <DropdownMenuContent align="end" className="z-[10060] w-56">
        <DropdownMenuLabel className="text-[12px] font-normal text-muted-foreground">
          {t('Pause mobile pushes from every organization')}
        </DropdownMenuLabel>
        {SNOOZE_DURATIONS.map((duration) => (
          <DropdownMenuItem
            key={duration.minutes}
            onSelect={() => onSnooze(duration.minutes)}
          >
            <MenuItemContent icon={Clock}>{t(duration.label)}</MenuItemContent>
          </DropdownMenuItem>
        ))}
        {snoozedUntil ? (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={onResume}>
              <MenuItemContent icon={BellRing}>
                {t('Resume pushes')}
              </MenuItemContent>
            </DropdownMenuItem>
          </>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

type Tab = 'inbox' | 'updates'
type Filter = 'all' | 'unread'

export function NotificationCenterPopover() {
  const t = useT()
  const navigate = useNavigate()
  const { account } = useAuth()
  const [open, setOpen] = useState(false)
  const [tab, setTab] = useState<Tab>('inbox')
  const [filter, setFilter] = useState<Filter>('all')
  const [showSettings, setShowSettings] = useState(false)
  const { notifications, unreadCount, isLoading, isFetching, refetch } =
    useConsoleNotifications(true)
  const updateReadMutation = useUpdateConsoleNotificationRead()
  const markAllReadMutation = useMarkAllConsoleNotificationsRead()
  const beepPrefs = useBeepPrefs(account as ConsoleAccountCache | undefined)
  const snooze = snoozeOf(beepPrefs.beep, null)

  const visible = useMemo(
    () =>
      filter === 'unread'
        ? notifications.filter((item) => !item.read)
        : notifications,
    [filter, notifications],
  )
  const groups = useMemo(() => groupByDay(visible), [visible])

  const handleOpenChange = (nextOpen: boolean) => {
    setOpen(nextOpen)
    if (nextOpen) {
      void refetch()
    } else {
      setShowSettings(false)
    }
  }

  const handleNotificationOpen = async (notification: Models.Notification) => {
    if (!notification.read) {
      try {
        await updateReadMutation.mutateAsync({
          notificationId: notification.$id,
          read: true,
        })
      } catch {
        // Navigation should still work when mark-read fails.
      }
    }

    const target = getNotificationNavigateTarget(notification)
    if (target) {
      setOpen(false)
      void navigate({
        to: target.to as never,
        params: target.params as never,
      })
    }
  }

  const handleToggleRead = (notification: Models.Notification) => {
    updateReadMutation.mutate(
      { notificationId: notification.$id, read: !notification.read },
      { onError: () => toast.error(t('Could not update the notification')) },
    )
  }

  const handleToggleMuteProject = (projectId: string, muted: boolean) => {
    beepPrefs.change((current) => withMutedProject(current, projectId, muted), {
      onSuccess: () =>
        toast.success(
          muted
            ? t('Project muted on your phone')
            : t('Project unmuted on your phone'),
          {
            description: muted
              ? t('Beep will not push its alerts. This inbox still shows them.')
              : undefined,
          },
        ),
      onError: () => toast.error(t('Could not update push settings')),
    })
  }

  const handleSnooze = (minutes: number) => {
    const until = new Date(Date.now() + minutes * 60_000)
    beepPrefs.change(
      (current) => withSnooze(current, EVERY_ORGANIZATION, until),
      {
        onSuccess: () =>
          toast.success(
            `${t('Pushes snoozed until')} ${formatUntil(until, t)}`,
            {
              description: t(
                'Alerts still fire and show here. Beep stays quiet until then.',
              ),
            },
          ),
        onError: () => toast.error(t('Could not snooze pushes')),
      },
    )
  }

  const handleResume = () => {
    beepPrefs.change((current) => withoutSnooze(current, EVERY_ORGANIZATION), {
      onSuccess: () => toast.success(t('Pushes are back on')),
      onError: () => toast.error(t('Could not update push settings')),
    })
  }

  const handleMarkAllRead = async () => {
    if (unreadCount === 0 || markAllReadMutation.isPending) return
    await markAllReadMutation.mutateAsync(notifications)
  }

  const unreadBadgeLabel =
    unreadCount > 9 ? '9+' : unreadCount > 0 ? String(unreadCount) : null

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="relative h-9 w-9 text-muted-foreground hover:bg-accent hover:text-foreground"
              aria-label={t('Notifications')}
              {...analyticsAttrs('notifications-open')}
            >
              {snooze ? (
                <BellOff className="h-4 w-4" />
              ) : (
                <Bell className="h-4 w-4" />
              )}
              {unreadBadgeLabel ? (
                <span className="absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold leading-none text-primary-foreground">
                  {unreadBadgeLabel}
                </span>
              ) : null}
            </Button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent className="z-[10060]">
          <p>
            {snooze
              ? `${t('Pushes snoozed until')} ${formatUntil(snooze.until, t)}`
              : t('Notifications')}
          </p>
        </TooltipContent>
      </Tooltip>
      <PopoverContent
        align="end"
        tabIndex={-1}
        // Focus the panel, not its first button, so no tooltip opens with it.
        onOpenAutoFocus={(event) => {
          event.preventDefault()
          ;(event.currentTarget as HTMLElement | null)?.focus()
        }}
        className="flex w-[min(26rem,calc(100vw-2rem))] flex-col overflow-hidden p-0 outline-none"
      >
        {showSettings ? (
          <>
            <div className="flex items-center gap-2 border-b border-border px-2 py-2.5">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-8 w-8 text-muted-foreground"
                onClick={() => setShowSettings(false)}
                aria-label={t('Back to notifications')}
              >
                <ArrowLeft className="h-4 w-4 rtl:rotate-180" />
              </Button>
              <h3 className="text-[14px] font-semibold text-foreground">
                {t('Notification settings')}
              </h3>
            </div>
            <div className="max-h-[min(30rem,70dvh)] overflow-y-auto overscroll-contain">
              <NotificationSettings
                beep={beepPrefs.beep}
                isSaving={beepPrefs.isPending}
                onChange={beepPrefs.change}
              />
            </div>
          </>
        ) : (
          <>
            <div className="flex items-center justify-between gap-2 px-4 pb-2 pt-3">
              <h3 className="text-[14px] font-semibold text-foreground">
                {t('Notifications')}
              </h3>
              <div className="flex items-center gap-0.5">
                {unreadCount > 0 && tab === 'inbox' ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 text-muted-foreground"
                        disabled={markAllReadMutation.isPending}
                        onClick={() => void handleMarkAllRead()}
                        aria-label={t('Mark all as read')}
                      >
                        {markAllReadMutation.isPending ? (
                          <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                          <CheckCheck className="h-4 w-4" />
                        )}
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent className="z-[10060]">
                      <p>{t('Mark all as read')}</p>
                    </TooltipContent>
                  </Tooltip>
                ) : null}
                <SnoozeMenu
                  snoozedUntil={snooze?.until ?? null}
                  disabled={!account}
                  onSnooze={handleSnooze}
                  onResume={handleResume}
                />
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="h-8 w-8 text-muted-foreground"
                      onClick={() => setShowSettings(true)}
                      aria-label={t('Notification settings')}
                    >
                      <Settings className="h-4 w-4" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent className="z-[10060]">
                    <p>{t('Notification settings')}</p>
                  </TooltipContent>
                </Tooltip>
              </div>
            </div>

            <div className="flex items-center justify-between gap-2 border-b border-border px-4 pb-2.5">
              <Tabs value={tab} onValueChange={(value) => setTab(value as Tab)}>
                <TabsList className="h-8">
                  <TabsTrigger
                    value="inbox"
                    className="h-6 gap-1.5 text-[12px]"
                  >
                    {t('Inbox')}
                    {unreadCount > 0 ? (
                      <span
                        className="rounded-full bg-primary/10 px-1.5 text-[10px] font-semibold text-primary"
                        dir="ltr"
                      >
                        {unreadCount}
                      </span>
                    ) : null}
                  </TabsTrigger>
                  <TabsTrigger value="updates" className="h-6 text-[12px]">
                    {t('Updates')}
                  </TabsTrigger>
                </TabsList>
              </Tabs>
              {tab === 'inbox' ? (
                <div
                  className="flex items-center gap-0.5 rounded-md bg-muted/50 p-0.5"
                  role="radiogroup"
                  aria-label={t('Filter notifications')}
                >
                  {(['all', 'unread'] as const).map((value) => (
                    <button
                      key={value}
                      type="button"
                      role="radio"
                      aria-checked={filter === value}
                      onClick={() => setFilter(value)}
                      className={cn(
                        'rounded px-2 py-1 text-[11px] font-medium text-muted-foreground transition-colors hover:text-foreground',
                        filter === value &&
                          'bg-background text-foreground shadow-sm',
                      )}
                    >
                      {value === 'all' ? t('All') : t('Unread')}
                    </button>
                  ))}
                </div>
              ) : null}
            </div>

            {snooze ? (
              <div className="flex items-center gap-2 border-b border-border bg-muted/30 px-4 py-2 text-[12px] text-muted-foreground">
                <BellOff className="h-3.5 w-3.5 shrink-0" aria-hidden />
                <span className="min-w-0 flex-1">
                  {t('Pushes snoozed until')} {formatUntil(snooze.until, t)}
                </span>
                <button
                  type="button"
                  onClick={handleResume}
                  className="shrink-0 font-medium text-foreground hover:underline"
                >
                  {t('Resume')}
                </button>
              </div>
            ) : null}

            <div className="max-h-[min(26rem,60dvh)] overflow-x-hidden overflow-y-auto overscroll-contain">
              {tab === 'updates' ? (
                <Suspense
                  fallback={
                    <div className="flex items-center justify-center gap-2 px-4 py-10 text-[13px] text-muted-foreground">
                      <Loader2 className="h-4 w-4 animate-spin" />
                      {t('Loading updates...')}
                    </div>
                  }
                >
                  <NotificationUpdates onNavigate={() => setOpen(false)} />
                </Suspense>
              ) : isLoading && notifications.length === 0 ? (
                <div className="flex items-center justify-center gap-2 px-4 py-10 text-[13px] text-muted-foreground">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  {t('Loading notifications...')}
                </div>
              ) : visible.length === 0 ? (
                <div className="px-4 py-10 text-center">
                  <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full border border-border bg-muted/40 text-muted-foreground">
                    {filter === 'unread' && notifications.length > 0 ? (
                      <CheckCheck className="h-4 w-4" />
                    ) : (
                      <Bell className="h-4 w-4" />
                    )}
                  </div>
                  <p className="text-[13px] font-medium text-foreground">
                    {filter === 'unread' && notifications.length > 0
                      ? t('You are all caught up')
                      : t('No notifications yet')}
                  </p>
                  <p className="mt-1 text-[12px] text-muted-foreground">
                    {t(
                      'We will notify you here when something needs your attention.',
                    )}
                  </p>
                </div>
              ) : (
                groups.map((group) => (
                  <section key={group.day}>
                    <h4 className="sticky top-0 z-20 border-b border-border bg-popover/95 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground backdrop-blur">
                      {t(DAY_LABELS[group.day])}
                    </h4>
                    <div className="divide-y divide-border">
                      {group.items.map((notification) => (
                        <NotificationRow
                          key={notification.$id}
                          notification={notification}
                          projectMuted={beepPrefs.beep.mutedProjectIds.includes(
                            notification.projectId ?? '',
                          )}
                          onOpen={handleNotificationOpen}
                          onToggleRead={handleToggleRead}
                          onToggleMuteProject={handleToggleMuteProject}
                        />
                      ))}
                    </div>
                  </section>
                ))
              )}
            </div>

            {isFetching && notifications.length > 0 && tab === 'inbox' ? (
              <div className="border-t border-border px-4 py-2 text-[11px] text-muted-foreground">
                {t('Refreshing...')}
              </div>
            ) : null}
          </>
        )}
      </PopoverContent>
    </Popover>
  )
}
