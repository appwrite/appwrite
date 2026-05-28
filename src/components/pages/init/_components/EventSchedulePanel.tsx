import { useEffect, useMemo, useState } from 'react'
import type {
  InitDisplayEvent,
  LaunchEventScheduleItem,
  LaunchSchedulePlatform,
} from '@/lib/init/types'
import { isLaunchEventDayLocked } from '@/lib/init/types'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { buildInitCheckingScheduleActivity } from '@/lib/init/init-presence-activity'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ArrowUpRight, ChevronLeft, ChevronRight } from 'lucide-react'
import { cn } from '@/lib/utils'

const CARD_SHELL =
  'rounded-xl border border-border bg-card/50 overflow-hidden'

const PLATFORM_META: Record<
  LaunchSchedulePlatform,
  { label: string; icon: string }
> = {
  youtube: { label: 'YouTube', icon: '/icons/youtube.svg' },
  discord: { label: 'Discord', icon: '/icons/discord-simple.svg' },
  reddit: { label: 'Reddit', icon: '/icons/reddit.svg' },
}

interface EventSchedulePanelProps {
  event: InitDisplayEvent
  /** When true, sticky positioning is handled by the parent column wrapper. */
  embedded?: boolean
}

function ScheduleRow({
  item,
  isRecapMode = false,
}: {
  item: LaunchEventScheduleItem
  isRecapMode?: boolean
}) {
  const meta = PLATFORM_META[item.platform]
  const { setTransientActivity } = useInitPresenceActivity()
  const sessionActivity = `Checking: ${item.title}`
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

export function EventSchedulePanel({
  event,
  embedded = false,
}: EventSchedulePanelProps) {
  const unlockedScheduleDays = useMemo(() => {
    const fromDays = event.days
      .filter((day) => !isLaunchEventDayLocked(day))
      .map((day) => day.day)
    const fromSchedule = event.schedule.map((item) => item.day)
    return [...new Set([...fromDays, ...fromSchedule])].sort((a, b) => a - b)
  }, [event.days, event.schedule])

  const defaultDay =
    event.currentDay > 0 &&
    unlockedScheduleDays.includes(event.currentDay)
      ? event.currentDay
      : unlockedScheduleDays[unlockedScheduleDays.length - 1] ?? 1

  const [selectedDay, setSelectedDay] = useState(defaultDay)
  const { setTransientActivity } = useInitPresenceActivity()

  useEffect(() => {
    setSelectedDay(defaultDay)
  }, [defaultDay])

  const selectedDayInfo = event.days.find((day) => day.day === selectedDay)
  const dayEvents = event.schedule.filter((item) => item.day === selectedDay)

  const selectedIndex = unlockedScheduleDays.indexOf(selectedDay)
  const canGoPrevious = selectedIndex > 0
  const canGoNext =
    selectedIndex >= 0 && selectedIndex < unlockedScheduleDays.length - 1

  if (event.schedule.length === 0 || unlockedScheduleDays.length === 0) {
    return null
  }

  const selectedTitle =
    selectedDayInfo && !isLaunchEventDayLocked(selectedDayInfo)
      ? selectedDayInfo.title
      : null

  return (
    <div
      className={cn(
        CARD_SHELL,
        !embedded && 'lg:sticky lg:self-start lg:top-20',
      )}
      onMouseEnter={() =>
        setTransientActivity(buildInitCheckingScheduleActivity(selectedDay))
      }
      onMouseLeave={() => setTransientActivity(null)}
    >
      <div className="px-6 py-4">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h3 className="text-[15px] font-semibold text-foreground">
              {event.isRecapMode ? 'Session replays' : 'Schedule'}
            </h3>
            <p className="mt-1 text-[13px] text-muted-foreground">
              {event.isRecapMode
                ? 'Session replays and community hangouts'
                : selectedDayInfo &&
                    !isLaunchEventDayLocked(selectedDayInfo) &&
                    selectedDayInfo.isLive
                  ? "Today's sessions"
                  : 'Discord and Reddit sessions'}
            </p>
          </div>
          {unlockedScheduleDays.length > 1 ? (
            <div className="flex shrink-0 items-center gap-0.5">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-7"
                disabled={!canGoPrevious}
                aria-label="Previous day"
                onClick={() => {
                  if (!canGoPrevious) return
                  setSelectedDay(unlockedScheduleDays[selectedIndex - 1])
                }}
              >
                <ChevronLeft className="size-4" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-7"
                disabled={!canGoNext}
                aria-label="Next day"
                onClick={() => {
                  if (!canGoNext) return
                  setSelectedDay(unlockedScheduleDays[selectedIndex + 1])
                }}
              >
                <ChevronRight className="size-4" />
              </Button>
            </div>
          ) : null}
        </div>
        <p className="mt-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          Day {selectedDay}
          {selectedDayInfo && !isLaunchEventDayLocked(selectedDayInfo)
            ? ` · ${selectedDayInfo.dateLabel}`
            : null}
          {selectedTitle ? ` · ${selectedTitle}` : null}
        </p>
      </div>
      <div className="border-t border-border" />
      {dayEvents.length > 0 ? (
        <ol className="divide-y divide-border">
          {dayEvents.map((item) => (
            <ScheduleRow
              key={item.id}
              item={item}
              isRecapMode={event.isRecapMode}
            />
          ))}
        </ol>
      ) : (
        <div className="px-6 py-8 text-center">
          <p className="text-[13px] text-muted-foreground">
            No sessions scheduled for this day.
          </p>
        </div>
      )}
    </div>
  )
}
