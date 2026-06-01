import type { LaunchEventScheduleItem, LaunchSchedulePlatform } from '@/lib/init/types'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { buildInitCheckingScheduleActivity } from '@/lib/init/init-presence-activity'
import { Badge } from '@/components/ui/badge'
import { ArrowUpRight } from 'lucide-react'
import { cn } from '@/lib/utils'

export const INIT_SCHEDULE_PLATFORM_META: Record<
  LaunchSchedulePlatform,
  { label: string; icon: string }
> = {
  youtube: { label: 'YouTube', icon: '/icons/youtube.svg' },
  discord: { label: 'Discord', icon: '/icons/discord-simple.svg' },
  reddit: { label: 'Reddit', icon: '/icons/reddit.svg' },
}

export function InitScheduleRow({
  item,
  isRecapMode = false,
}: {
  item: LaunchEventScheduleItem
  isRecapMode?: boolean
}) {
  const meta = INIT_SCHEDULE_PLATFORM_META[item.platform]
  const { setTransientActivity } = useInitPresenceActivity()
  const sessionActivity = buildInitCheckingScheduleActivity(item.day)
  const content = (
    <>
      <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted">
        <img
          src={meta.icon}
          alt=""
          className="size-4 dark:invert-0"
          aria-hidden
        />
      </span>
      <div className="min-w-0 flex-1 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <p className="text-[13px] font-medium text-foreground">{item.title}</p>
          {!isRecapMode && item.isLive ? (
            <Badge variant="error" className="text-[10px] shrink-0">
              Live
            </Badge>
          ) : null}
        </div>
        <p className="text-[11px] text-muted-foreground">
          {meta.label} · {item.timeLabel}
        </p>
      </div>
      {item.href ? (
        <ArrowUpRight
          className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground"
          aria-hidden
        />
      ) : null}
    </>
  )

  if (item.href) {
    return (
      <li>
        <a
          href={item.href}
          target="_blank"
          rel="noopener noreferrer"
          className={cn(
            'group flex items-start gap-3 px-6 py-3.5 transition-colors hover:bg-accent/30',
            !isRecapMode &&
              item.isLive &&
              'bg-[color-mix(in_srgb,var(--brand-cta)_5%,transparent)]',
          )}
          onMouseEnter={() => setTransientActivity(sessionActivity)}
          onMouseLeave={() => setTransientActivity(null)}
          onFocus={() => setTransientActivity(sessionActivity)}
          onBlur={() => setTransientActivity(null)}
        >
          {content}
        </a>
      </li>
    )
  }

  return (
    <li
      className={cn(
        'flex items-start gap-3 px-6 py-3.5',
        !isRecapMode &&
          item.isLive &&
          'bg-[color-mix(in_srgb,var(--brand-cta)_5%,transparent)]',
      )}
      onMouseEnter={() => setTransientActivity(sessionActivity)}
      onMouseLeave={() => setTransientActivity(null)}
    >
      {content}
    </li>
  )
}
