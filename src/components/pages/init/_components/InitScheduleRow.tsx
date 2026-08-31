import type {
  LaunchEvent,
  LaunchEventScheduleItem,
  LaunchSchedulePlatform,
} from '@/lib/init/types'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { buildInitCheckingScheduleActivity } from '@/lib/init/init-presence-activity'
import { Badge } from '@/components/ui/badge'
import { ArrowUpRight } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useInitHref } from '@/lib/init/use-init-href'
import { INIT_YOUTUBE_CHANNEL_HREF } from '@/lib/init/links'
import { useInitScheduleTime } from '@/lib/init/use-init-schedule-time'
import { useT } from '@/lib/i18n/translate'
import { InitScheduleCalendarButton } from './InitScheduleCalendarButton'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'

export const INIT_SCHEDULE_PLATFORM_META: Record<
  LaunchSchedulePlatform,
  { label: string; icon: string }
> = {
  youtube: { label: 'YouTube', icon: '/icons/youtube.svg' },
  discord: { label: 'Discord', icon: '/icons/discord-simple.svg' },
  reddit: { label: 'Reddit', icon: '/icons/reddit.svg' },
}

export function InitScheduleRow({
  event,
  item,
  isRecapMode = false,
  inlineWhenWide = false,
}: {
  event: LaunchEvent
  item: LaunchEventScheduleItem
  isRecapMode?: boolean
  inlineWhenWide?: boolean
}) {
  const t = useT()
  const meta = INIT_SCHEDULE_PLATFORM_META[item.platform]
  const { setTransientActivity } = useInitPresenceActivity()
  const formatScheduleTime = useInitScheduleTime()
  const sessionActivity = buildInitCheckingScheduleActivity(item.day)
  const actionHref =
    item.href ??
    (item.platform === 'youtube'
      ? event.liveBanner?.href ?? INIT_YOUTUBE_CHANNEL_HREF
      : undefined)
  const resolvedAction =
    useInitHref(actionHref) ??
    (item.platform === 'youtube'
      ? { href: INIT_YOUTUBE_CHANNEL_HREF, external: true }
      : null)
  const actionLabel = item.platform === 'youtube' ? 'Watch' : 'Join event'
  const actionExternal = resolvedAction?.external ?? false
  const showLive = !isRecapMode && item.isLive
  const showStartingSoon = !isRecapMode && item.isStartingSoon
  const badge = (
    <span
      className={cn(
        'flex shrink-0',
        inlineWhenWide ? 'w-[92px]' : 'w-6',
      )}
    >
      <Badge
        variant="secondary"
        className={cn(
          'rounded-md bg-muted text-[10px] font-semibold uppercase tracking-wider text-muted-foreground',
          inlineWhenWide ? 'gap-1.5 px-2 py-0.5' : 'size-6 justify-center p-0',
        )}
      >
        <img
          src={meta.icon}
          alt=""
          className={cn('size-3', PUBLIC_ICON_MUTED_CLASSES)}
          aria-hidden
        />
        {inlineWhenWide ? meta.label : null}
      </Badge>
    </span>
  )
  const details = (
    <div
      className={cn(
        'min-w-0 flex-1 space-y-1',
        inlineWhenWide &&
          'sm:flex sm:items-center sm:gap-3 sm:space-y-0',
      )}
    >
      <div className="flex min-w-0 flex-wrap items-center gap-2">
        <p className="min-w-0 truncate text-[13px] font-medium text-foreground">
          {item.title}
        </p>
        {showLive ? (
          <Badge variant="error" className="text-[10px] shrink-0">
            {t('Live')}
          </Badge>
        ) : showStartingSoon ? (
          <Badge variant="warning" className="text-[10px] shrink-0">
            {t('Starting soon')}
          </Badge>
        ) : null}
      </div>
      {/* Day-card row: badge sits inline with the title, so skip the muted status. */}
      {inlineWhenWide && (showLive || showStartingSoon) ? null : (
        <p
          className={cn(
            'text-[11px] text-muted-foreground',
            inlineWhenWide && 'sm:shrink-0',
          )}
        >
          {showLive ? t('Live now') : formatScheduleTime(item.startsAt)}
        </p>
      )}
    </div>
  )
  const mainContent =
    !inlineWhenWide && resolvedAction ? (
      <a
        href={resolvedAction.href}
        {...(actionExternal ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
        className="flex min-w-0 flex-1 items-start gap-4"
      >
        {badge}
        {details}
      </a>
    ) : (
      <>
        {badge}
        {details}
      </>
    )

  return (
    <li
      className={cn(
        'group flex items-start gap-4 px-6 py-3.5',
        inlineWhenWide && 'sm:items-center',
        showLive &&
          'bg-[color-mix(in_srgb,var(--brand-cta)_5%,transparent)]',
        showStartingSoon &&
          !showLive &&
          'bg-[color-mix(in_srgb,var(--brand-cta)_3%,transparent)]',
      )}
      onMouseEnter={() => setTransientActivity(sessionActivity)}
      onMouseLeave={() => setTransientActivity(null)}
      onFocus={() => setTransientActivity(sessionActivity)}
      onBlur={() => setTransientActivity(null)}
    >
      {mainContent}
      <div className="flex shrink-0 items-center gap-3">
        {!isRecapMode ? (
          <InitScheduleCalendarButton event={event} item={item} />
        ) : null}
        {inlineWhenWide && resolvedAction ? (
          <>
            {!isRecapMode ? (
              <span
                aria-hidden
                className="size-1 rounded-full bg-muted-foreground/40"
              />
            ) : null}
            <a
              href={resolvedAction.href}
              {...(actionExternal ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
              className="flex shrink-0 items-center gap-1 text-[12px] text-muted-foreground transition-colors group-hover:text-foreground hover:text-foreground"
            >
              {t(actionLabel)}
              <ArrowUpRight className="size-3.5" aria-hidden />
            </a>
          </>
        ) : null}
      </div>
    </li>
  )
}
