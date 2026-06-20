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
import { isExternalInitHref } from '@/lib/init/links'
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

const INIT_YOUTUBE_FALLBACK_HREF = 'https://www.youtube.com/@Appwrite'

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
  const meta = INIT_SCHEDULE_PLATFORM_META[item.platform]
  const { setTransientActivity } = useInitPresenceActivity()
  const sessionActivity = buildInitCheckingScheduleActivity(item.day)
  const actionHref =
    item.href ??
    (item.platform === 'youtube'
      ? event.liveBanner?.href ?? INIT_YOUTUBE_FALLBACK_HREF
      : undefined)
  const actionLabel = item.platform === 'youtube' ? 'Watch' : 'Join event'
  const actionExternal = actionHref ? isExternalInitHref(actionHref) : false
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
        {!isRecapMode && item.isLive ? (
          <Badge variant="error" className="text-[10px] shrink-0">
            Live
          </Badge>
        ) : null}
      </div>
      <p
        className={cn(
          'text-[11px] text-muted-foreground',
          inlineWhenWide && 'sm:shrink-0',
        )}
      >
        {item.timeLabel}
      </p>
    </div>
  )
  const mainContent =
    !inlineWhenWide && actionHref ? (
      <a
        href={actionHref}
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
        !isRecapMode &&
          item.isLive &&
          'bg-[color-mix(in_srgb,var(--brand-cta)_5%,transparent)]',
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
        {inlineWhenWide && actionHref ? (
          <>
            {!isRecapMode ? (
              <span
                aria-hidden
                className="size-1 rounded-full bg-muted-foreground/40"
              />
            ) : null}
            <a
              href={actionHref}
              {...(actionExternal ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
              className="flex shrink-0 items-center gap-1 text-[12px] text-muted-foreground transition-colors group-hover:text-foreground hover:text-foreground"
            >
              {actionLabel}
              <ArrowUpRight className="size-3.5" aria-hidden />
            </a>
          </>
        ) : null}
      </div>
    </li>
  )
}
