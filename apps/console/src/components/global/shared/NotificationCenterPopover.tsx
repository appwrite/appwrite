import { lazy, Suspense, useMemo, useState, type ReactNode } from 'react'
import type { Models } from '@appwrite.io/console'
import { useNavigate, useRouter } from '@tanstack/react-router'
import { toast } from 'sonner'
import {
  ArrowLeft,
  ArrowUpRight,
  Bell,
  BellOff,
  BellRing,
  Check,
  CheckCheck,
  ChevronDown,
  Circle,
  CircleAlert,
  CircleCheck,
  Clock,
  Copy,
  ExternalLink,
  FileJson,
  Info,
  Link2,
  Loader2,
  MailOpen,
  MoreHorizontal,
  Settings,
  Square,
  TriangleAlert,
  UserCheck,
  type LucideIcon,
} from 'lucide-react'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { cn } from '@/lib/utils'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import {
  getAppwriteErrorInfo,
  getErrorMessage,
} from '@/lib/utils/error-formatting'
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
  useAcknowledgeAlert,
  useBeepPrefs,
  useConsoleNotifications,
  useMarkAllConsoleNotificationsRead,
  useUpdateConsoleNotificationRead,
} from '@/lib/react-query/hooks/notifications'
import type { ConsoleAccountCache } from '@/lib/react-query/hooks/auth'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { BeepPromotion } from './BeepPromotion'
import { formatUntil, NotificationSettings } from './NotificationSettings'

// The changelog ships every entry's text, so it loads only when the tab opens.
const NotificationUpdates = lazy(() =>
  import('./NotificationUpdates').then((module) => ({
    default: module.NotificationUpdates,
  })),
)

/** Menus and tooltips opened from inside the popover sit above it. */
const ABOVE_POPOVER = 'z-[10060]'

type Severity = 'error' | 'warning' | 'info'

function severityOf(type: string): Severity {
  const normalized = type.trim().toLowerCase()
  if (normalized === 'error' || normalized === 'critical') return 'error'
  if (normalized === 'warning') return 'warning'
  return 'info'
}

const SEVERITY_ICONS = {
  error: CircleAlert,
  warning: TriangleAlert,
  info: Info,
} as const

const SEVERITY_GLYPHS: Record<Severity, string> = {
  error: 'text-red-600 dark:text-red-400',
  warning: 'text-amber-600 dark:text-amber-500',
  info: 'text-muted-foreground',
}

const RESOLVED_GLYPH = 'text-green-600 dark:text-green-500'

/**
 * Whether the notification is about an alert that stopped firing. Rows stored
 * before notifications named their alert carry no status, only the
 * "[Resolved]" the Alerts worker puts at the front of the title.
 */
function isResolvedAlert(notification: Models.Notification): boolean {
  if (notification.subjectType === 'alerts') {
    return notification.status === 'resolved'
  }
  return notification.title.startsWith('[Resolved]')
}

/** A neutral tile with the icon in the notification's severity color. */
export function NotificationIcon({
  icon: Icon,
  className,
}: {
  icon: typeof Info
  className?: string
}) {
  return (
    <div className="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
      <Icon className={cn('size-4', className)} aria-hidden />
    </div>
  )
}

/** The unread marker, on the row's start edge. */
export function UnreadDot() {
  return (
    <span
      className="absolute start-2 top-[1.125rem] size-1.5 rounded-full bg-primary"
      aria-hidden
    />
  )
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

function HeaderButton({
  label,
  disabled,
  active,
  concealed,
  onClick,
  children,
}: {
  label: string
  disabled?: boolean
  active?: boolean
  /** Hidden, but keeps its place so the other buttons don't move. */
  concealed?: boolean
  onClick?: () => void
  children: ReactNode
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className={cn(
            'h-8 w-8 text-muted-foreground hover:text-foreground',
            active && 'text-foreground',
            concealed && 'invisible',
          )}
          disabled={disabled || concealed}
          aria-hidden={concealed || undefined}
          onClick={onClick}
          aria-label={label}
        >
          {children}
        </Button>
      </TooltipTrigger>
      <TooltipContent className={ABOVE_POPOVER}>
        <p>{label}</p>
      </TooltipContent>
    </Tooltip>
  )
}

const MENU_PRIMITIVES = {
  context: {
    Item: ContextMenuItem,
    Separator: ContextMenuSeparator,
    Sub: ContextMenuSub,
    SubTrigger: ContextMenuSubTrigger,
    SubContent: ContextMenuSubContent,
  },
  dropdown: {
    Item: DropdownMenuItem,
    Separator: DropdownMenuSeparator,
    Sub: DropdownMenuSub,
    SubTrigger: DropdownMenuSubTrigger,
    SubContent: DropdownMenuSubContent,
  },
} as const

/**
 * Notification actions shared by the row context menu and the row ⋯ menu, so
 * both list the same items in the same order.
 */
function NotificationMenuItems({
  variant,
  notification,
  projectMuted,
  onOpen,
  onToggleRead,
  onToggleMuteProject,
  onAcknowledge,
}: {
  variant: 'context' | 'dropdown'
  notification: Models.Notification
  projectMuted: boolean
  onOpen: (notification: Models.Notification) => void
  onToggleRead: (notification: Models.Notification) => void
  onToggleMuteProject: (projectId: string, muted: boolean) => void
  onAcknowledge: (notification: Models.Notification) => void
}) {
  const t = useT()
  const router = useRouter()
  const { Item, Separator, Sub, SubTrigger, SubContent } =
    MENU_PRIMITIVES[variant]
  const projectId = notification.projectId?.trim() ?? ''
  const target = getNotificationNavigateTarget(notification)
  const href = target
    ? buildConsoleUrl(
        router.buildLocation({
          to: target.to as never,
          params: target.params as never,
        }).href,
      )
    : null
  const isAlert =
    notification.subjectType === 'alerts' && !!notification.subjectId
  // A firing alert of a project; an organization's budget alert has no project to acknowledge it in.
  const canAcknowledge =
    isAlert &&
    notification.status === 'firing' &&
    !!projectId &&
    projectId !== 'console'

  const row = (icon: LucideIcon, label: ReactNode) => (
    <>
      <MenuItemIcon icon={icon} />
      {label}
    </>
  )

  return (
    <>
      {target ? (
        <Item onSelect={() => onOpen(notification)}>
          {row(ArrowUpRight, t('Open'))}
        </Item>
      ) : null}
      {canAcknowledge ? (
        <Item onSelect={() => onAcknowledge(notification)}>
          {row(UserCheck, t('Acknowledge'))}
        </Item>
      ) : null}
      <Item onSelect={() => onToggleRead(notification)}>
        {notification.read
          ? row(Circle, t('Mark as unread'))
          : row(MailOpen, t('Mark as read'))}
      </Item>
      {projectId ? (
        <>
          <Separator />
          <Item onSelect={() => onToggleMuteProject(projectId, !projectMuted)}>
            {projectMuted
              ? row(BellRing, t('Unmute project pushes'))
              : row(BellOff, t('Mute project pushes'))}
          </Item>
        </>
      ) : null}
      <Separator />
      <Sub>
        <SubTrigger>{row(Copy, t('Copy'))}</SubTrigger>
        <SubContent className={ABOVE_POPOVER}>
          <Item onSelect={() => copyToClipboard(t('ID'), notification.$id)}>
            {row(Copy, t('Copy ID'))}
          </Item>
          {notification.title ? (
            <Item
              onSelect={() => copyToClipboard(t('Title'), notification.title)}
            >
              {row(Copy, t('Copy title'))}
            </Item>
          ) : null}
          {href ? (
            <Item onSelect={() => copyToClipboard(t('Link'), href)}>
              {row(Link2, t('Copy link'))}
            </Item>
          ) : null}
          <Item onSelect={() => void copyResourceAsJson(() => notification)}>
            {row(FileJson, t('Copy as JSON'))}
          </Item>
        </SubContent>
      </Sub>
      {href ? (
        <>
          <Separator />
          <Item onSelect={() => openInNewTab(href)}>
            {row(ExternalLink, t('Open in new tab'))}
          </Item>
          <Item onSelect={() => openInNewWindow(href)}>
            {row(Square, t('Open in new window'))}
          </Item>
        </>
      ) : null}
    </>
  )
}

function NotificationRow({
  notification,
  projectMuted,
  onOpen,
  onToggleRead,
  onToggleMuteProject,
  onAcknowledge,
}: {
  notification: Models.Notification
  projectMuted: boolean
  onOpen: (notification: Models.Notification) => void
  onToggleRead: (notification: Models.Notification) => void
  onToggleMuteProject: (projectId: string, muted: boolean) => void
  onAcknowledge: (notification: Models.Notification) => void
}) {
  const t = useT()
  const isUnread = !notification.read
  const severity = severityOf(notification.type)
  const resolved = isResolvedAlert(notification)
  const isAlert =
    notification.subjectType === 'alerts' && !!notification.subjectId
  const menuProps = {
    notification,
    projectMuted,
    onOpen,
    onToggleRead,
    onToggleMuteProject,
    onAcknowledge,
  }

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>
        <div className="group relative flex gap-3 px-5 py-3 transition-colors hover:bg-accent/50 data-[state=open]:bg-accent/50">
          <button
            type="button"
            onClick={() => onOpen(notification)}
            className="absolute inset-0 z-0 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
            aria-label={notification.title}
          />
          {isUnread ? <UnreadDot /> : null}
          <div className="pointer-events-none relative">
            <NotificationIcon
              icon={resolved ? CircleCheck : SEVERITY_ICONS[severity]}
              className={resolved ? RESOLVED_GLYPH : SEVERITY_GLYPHS[severity]}
            />
          </div>
          <div className="pointer-events-none relative min-w-0 flex-1 overflow-hidden">
            <p
              className={cn(
                'break-words pe-7 text-[13px] leading-snug',
                isUnread ? 'font-medium text-foreground' : 'text-foreground/80',
              )}
            >
              {notification.title}
            </p>
            {notification.body?.trim() ? (
              <p className="mt-1 line-clamp-2 break-words text-[12px] leading-relaxed text-muted-foreground">
                {notification.body}
              </p>
            ) : null}
            <div className="mt-1.5 flex items-center gap-1.5 text-[11px] text-muted-foreground">
              <DateTooltip
                date={notification.$createdAt}
                live
                className="pointer-events-auto text-[11px] text-muted-foreground"
              />
              {isAlert && notification.status === 'acknowledged' ? (
                <>
                  <span aria-hidden>·</span>
                  <span>{t('Acknowledged')}</span>
                </>
              ) : null}
              {projectMuted ? (
                <>
                  <span aria-hidden>·</span>
                  <span>{t('Pushes muted')}</span>
                </>
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
            <DropdownMenuContent
              align="end"
              className={cn(ABOVE_POPOVER, 'w-56')}
            >
              <NotificationMenuItems variant="dropdown" {...menuProps} />
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </ContextMenuTrigger>
      <ContextMenuContent className={cn(ABOVE_POPOVER, 'w-56')}>
        <NotificationMenuItems variant="context" {...menuProps} />
      </ContextMenuContent>
    </ContextMenu>
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
                'h-8 w-8 text-muted-foreground hover:text-foreground',
                snoozedUntil && 'text-foreground',
              )}
              aria-label={t('Snooze pushes')}
            >
              <Clock className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
        </TooltipTrigger>
        <TooltipContent className={ABOVE_POPOVER}>
          <p>{t('Snooze pushes')}</p>
        </TooltipContent>
      </Tooltip>
      <DropdownMenuContent align="end" className={cn(ABOVE_POPOVER, 'w-60')}>
        <DropdownMenuLabel className="text-[12px] font-normal leading-snug text-muted-foreground">
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

const FILTER_LABELS: Record<Filter, string> = {
  all: 'All',
  unread: 'Unread',
}

function FilterMenu({
  filter,
  onChange,
}: {
  filter: Filter
  onChange: (filter: Filter) => void
}) {
  const t = useT()
  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="-me-2 mb-1.5 h-7 gap-1 px-2 text-[12px] font-medium text-muted-foreground hover:text-foreground"
          aria-label={t('Filter notifications')}
        >
          {t(FILTER_LABELS[filter])}
          <ChevronDown className="size-3.5" aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className={cn(ABOVE_POPOVER, 'w-40')}>
        {(['all', 'unread'] as const).map((value) => (
          <DropdownMenuItem
            key={value}
            role="menuitemradio"
            aria-checked={filter === value}
            onSelect={() => onChange(value)}
          >
            <span className="min-w-0 flex-1">{t(FILTER_LABELS[value])}</span>
            {filter === value ? (
              <Check className="size-4 text-foreground" aria-hidden />
            ) : null}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

const TAB_LIST =
  'h-auto gap-5 rounded-none bg-transparent p-0 dark:bg-transparent'
const TAB_TRIGGER =
  'h-auto flex-none gap-1.5 rounded-none border-0 border-b-2 border-transparent px-0 pb-2 pt-0 text-[13px] shadow-none data-[state=active]:border-foreground data-[state=active]:bg-transparent data-[state=active]:shadow-none dark:data-[state=active]:border-foreground dark:data-[state=active]:bg-transparent'

function Centered({ children }: { children: ReactNode }) {
  return (
    <div className="flex h-full items-center justify-center gap-2 px-5 py-12 text-[13px] text-muted-foreground">
      {children}
    </div>
  )
}

export function NotificationCenterPopover() {
  const t = useT()
  const navigate = useNavigate()
  const { account } = useAuth()
  const [open, setOpen] = useState(false)
  const [tab, setTab] = useState<Tab>('inbox')
  const [filter, setFilter] = useState<Filter>('all')
  const [showSettings, setShowSettings] = useState(false)
  const { notifications, unreadCount, isLoading, refetch } =
    useConsoleNotifications(true)
  const updateReadMutation = useUpdateConsoleNotificationRead()
  const markAllReadMutation = useMarkAllConsoleNotificationsRead()
  const acknowledgeMutation = useAcknowledgeAlert()
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
        ...(target.search ? { search: target.search as never } : {}),
      })
    }
  }

  const handleAcknowledge = (notification: Models.Notification) => {
    acknowledgeMutation.mutate(
      {
        projectId: notification.projectId ?? '',
        alertId: notification.subjectId,
      },
      {
        onSuccess: () => {
          if (!notification.read) {
            updateReadMutation.mutate({
              notificationId: notification.$id,
              read: true,
            })
          }
          toast.success(t('Alert acknowledged'), {
            description: t(
              "Your team sees that you're on it. It keeps firing until its monitor recovers.",
            ),
          })
        },
        onError: (error) => {
          const { type, code } = getAppwriteErrorInfo(error)
          if (type === 'alert_resolved') {
            toast.error(t('This alert has already resolved'), {
              description: t('Only a firing alert can be acknowledged.'),
            })
          } else if (code === 401) {
            toast.error(t('Failed to acknowledge the alert'), {
              description: t(
                "Your role can't change alerts. Ask an owner or a developer.",
              ),
            })
          } else {
            toast.error(
              getErrorMessage(error, t('Failed to acknowledge the alert')),
            )
          }
        },
      },
    )
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
          muted ? t('Project pushes muted') : t('Project pushes unmuted'),
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
        <TooltipContent>
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
        // One height for every tab and view, so switching never resizes it.
        className="flex h-[min(38rem,calc(100dvh-5rem))] w-[min(26rem,calc(100vw-2rem))] flex-col overflow-hidden p-0 outline-none"
      >
        {showSettings ? (
          <>
            <div className="flex items-center gap-1.5 border-b border-border px-2 py-2.5">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-8 w-8 text-muted-foreground hover:text-foreground"
                onClick={() => setShowSettings(false)}
                aria-label={t('Back to notifications')}
              >
                <ArrowLeft className="h-4 w-4 rtl:rotate-180" />
              </Button>
              <h3 className="text-[14px] font-semibold text-foreground">
                {t('Notification settings')}
              </h3>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
              <NotificationSettings
                beep={beepPrefs.beep}
                isSaving={beepPrefs.isPending}
                onChange={beepPrefs.change}
              />
            </div>
          </>
        ) : (
          <>
            <div className="flex items-center justify-between gap-2 px-5 pb-1 pt-3">
              <h3 className="text-[14px] font-semibold text-foreground">
                {t('Notifications')}
              </h3>
              <div className="-me-2 flex items-center">
                <HeaderButton
                  label={t('Mark all as read')}
                  concealed={unreadCount === 0 || tab !== 'inbox'}
                  disabled={markAllReadMutation.isPending}
                  onClick={() => void handleMarkAllRead()}
                >
                  {markAllReadMutation.isPending ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <CheckCheck className="h-4 w-4" />
                  )}
                </HeaderButton>
                <SnoozeMenu
                  snoozedUntil={snooze?.until ?? null}
                  disabled={!account}
                  onSnooze={handleSnooze}
                  onResume={handleResume}
                />
                <HeaderButton
                  label={t('Notification settings')}
                  onClick={() => setShowSettings(true)}
                >
                  <Settings className="h-4 w-4" />
                </HeaderButton>
              </div>
            </div>

            <div className="flex items-end justify-between gap-2 border-b border-border px-5 pt-1.5">
              <Tabs value={tab} onValueChange={(value) => setTab(value as Tab)}>
                <TabsList className={TAB_LIST}>
                  <TabsTrigger value="inbox" className={TAB_TRIGGER}>
                    {t('Inbox')}
                    {unreadCount > 0 ? (
                      <span
                        className="rounded bg-muted px-1.5 text-[11px] font-medium tabular-nums text-muted-foreground"
                        dir="ltr"
                      >
                        {unreadCount}
                      </span>
                    ) : null}
                  </TabsTrigger>
                  <TabsTrigger value="updates" className={TAB_TRIGGER}>
                    {t('Updates')}
                  </TabsTrigger>
                </TabsList>
              </Tabs>
              <div
                className={cn(tab !== 'inbox' && 'invisible')}
                aria-hidden={tab !== 'inbox' || undefined}
              >
                <FilterMenu filter={filter} onChange={setFilter} />
              </div>
            </div>

            {snooze ? (
              <div className="flex items-center gap-2 border-b border-border bg-muted/30 px-5 py-2 text-[12px] text-muted-foreground">
                <BellOff className="size-3.5 shrink-0" aria-hidden />
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

            <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain">
              {tab === 'updates' ? (
                <Suspense
                  fallback={
                    <Centered>
                      <Loader2 className="h-4 w-4 animate-spin" />
                      {t('Loading updates...')}
                    </Centered>
                  }
                >
                  <NotificationUpdates onNavigate={() => setOpen(false)} />
                </Suspense>
              ) : isLoading && notifications.length === 0 ? (
                <Centered>
                  <Loader2 className="h-4 w-4 animate-spin" />
                  {t('Loading notifications...')}
                </Centered>
              ) : visible.length === 0 ? (
                <div className="flex h-full flex-col items-center justify-center px-6 py-12 text-center">
                  <p className="text-[13px] font-medium text-foreground">
                    {filter === 'unread' && notifications.length > 0
                      ? t('No unread notifications')
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
                    <h4 className="sticky top-0 z-20 bg-popover px-5 pb-1.5 pt-3 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                      {t(DAY_LABELS[group.day])}
                    </h4>
                    <div className="divide-y divide-border/70">
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
                          onAcknowledge={handleAcknowledge}
                        />
                      ))}
                    </div>
                  </section>
                ))
              )}
            </div>
            <BeepPromotion />
          </>
        )}
      </PopoverContent>
    </Popover>
  )
}
