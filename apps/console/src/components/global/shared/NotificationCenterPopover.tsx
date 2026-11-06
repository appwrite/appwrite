import { useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { useNavigate } from '@tanstack/react-router'
import { AlertCircle, Bell, CheckCheck, Info, Loader2 } from 'lucide-react'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { cn } from '@/lib/utils'
import { getNotificationNavigateTarget } from '@/lib/notifications/notification-links'
import {
  useConsoleNotifications,
  useMarkAllConsoleNotificationsRead,
  useUpdateConsoleNotificationRead,
} from '@/lib/react-query/hooks/notifications'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'

function notificationTypeBadgeVariant(
  type: string,
): 'info' | 'warning' | 'error' {
  switch (type.trim().toLowerCase()) {
    case 'warning':
      return 'warning'
    case 'error':
      return 'error'
    default:
      return 'info'
  }
}

function NotificationTypeIcon({
  type,
  className,
}: {
  type: string
  className?: string
}) {
  const normalized = type.trim().toLowerCase()
  if (normalized === 'error' || normalized === 'warning') {
    return <AlertCircle className={className} aria-hidden />
  }
  return <Info className={className} aria-hidden />
}

function NotificationRow({
  notification,
  onOpen,
}: {
  notification: Models.Notification
  onOpen: (notification: Models.Notification) => void
}) {
  const t = useT()
  const isUnread = !notification.read

  return (
    <button
      type="button"
      onClick={() => onOpen(notification)}
      className={cn(
        'flex w-full gap-3 px-4 py-3 text-start transition-colors hover:bg-accent/60',
        isUnread && 'bg-muted/30',
      )}
    >
      <div
        className={cn(
          'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-border bg-background',
          isUnread && 'border-primary/20 bg-primary/5 text-primary',
        )}
      >
        <NotificationTypeIcon type={notification.type} className="h-4 w-4" />
      </div>
      <div className="min-w-0 flex-1 space-y-1.5 overflow-hidden">
        <div className="flex items-start justify-between gap-2">
          <p
            className={cn(
              'min-w-0 flex-1 break-words text-[13px] leading-snug text-foreground',
              isUnread && 'font-semibold',
            )}
          >
            {notification.title}
          </p>
          <Badge
            variant={notificationTypeBadgeVariant(notification.type)}
            className="text-[10px] shrink-0 capitalize"
          >
            {notification.type}
          </Badge>
        </div>
        {notification.body?.trim() ? (
          <p className="line-clamp-2 break-words text-[12px] leading-relaxed text-muted-foreground">
            {notification.body}
          </p>
        ) : null}
        <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
          <DateTooltip
            date={notification.$createdAt}
            live
            className="text-[11px] text-muted-foreground"
          />
          {isUnread ? (
            <span className="inline-flex items-center gap-1">
              <span className="size-1.5 rounded-full bg-primary" aria-hidden />
              {t('Unread')}
            </span>
          ) : null}
        </div>
      </div>
    </button>
  )
}

export function NotificationCenterPopover() {
  const t = useT()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const {
    notifications,
    unreadCount,
    isLoading,
    isFetching,
    refetch,
  } = useConsoleNotifications(true)
  const updateReadMutation = useUpdateConsoleNotificationRead()
  const markAllReadMutation = useMarkAllConsoleNotificationsRead()

  const handleOpenChange = (nextOpen: boolean) => {
    setOpen(nextOpen)
    if (nextOpen) {
      void refetch()
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
              <Bell className="h-4 w-4" />
              {unreadBadgeLabel ? (
                <span className="absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold leading-none text-primary-foreground">
                  {unreadBadgeLabel}
                </span>
              ) : null}
            </Button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent>
          <p>{t('Notifications')}</p>
        </TooltipContent>
      </Tooltip>
      <PopoverContent
        align="end"
        className="w-[min(24rem,calc(100vw-2rem))] overflow-hidden p-0"
      >
        <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
          <div className="min-w-0">
            <h3 className="text-[14px] font-semibold text-foreground">
              {t('Notifications')}
            </h3>
            {unreadCount > 0 ? (
              <p className="text-[12px] text-muted-foreground">
                {unreadCount === 1 ? (
                  t('1 unread notification')
                ) : (
                  <>
                    <span dir="ltr">{unreadCount}</span>{' '}
                    {t('unread notifications')}
                  </>
                )}
              </p>
            ) : (
              <p className="text-[12px] text-muted-foreground">
                {t('Stay updated on your projects and resources.')}
              </p>
            )}
          </div>
          {unreadCount > 0 ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 shrink-0 gap-1.5 text-[12px]"
              disabled={markAllReadMutation.isPending}
              onClick={() => void handleMarkAllRead()}
            >
              {markAllReadMutation.isPending ? (
                <Loader2 className="h-3.5 w-3.5 animate-spin" />
              ) : (
                <CheckCheck className="h-3.5 w-3.5" />
              )}
              {t('Mark all as read')}
            </Button>
          ) : null}
        </div>

        <div className="max-h-[min(24rem,60dvh)] overflow-x-hidden overflow-y-auto overscroll-contain">
          {isLoading && notifications.length === 0 ? (
            <div className="flex items-center justify-center gap-2 px-4 py-10 text-[13px] text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              {t('Loading notifications...')}
            </div>
          ) : notifications.length === 0 ? (
            <div className="px-4 py-10 text-center">
              <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full border border-border bg-muted/40 text-muted-foreground">
                <Bell className="h-4 w-4" />
              </div>
              <p className="text-[13px] font-medium text-foreground">
                {t('No notifications yet')}
              </p>
              <p className="mt-1 text-[12px] text-muted-foreground">
                {t('We will notify you here when something needs your attention.')}
              </p>
            </div>
          ) : (
            <div className="divide-y divide-border">
              {notifications.map((notification) => (
                <NotificationRow
                  key={notification.$id}
                  notification={notification}
                  onOpen={handleNotificationOpen}
                />
              ))}
            </div>
          )}
        </div>

        {isFetching && notifications.length > 0 ? (
          <div className="border-t border-border px-4 py-2 text-[11px] text-muted-foreground">
            {t('Refreshing...')}
          </div>
        ) : null}
      </PopoverContent>
    </Popover>
  )
}
