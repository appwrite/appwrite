import { useMemo, useState } from 'react'
import type {
  LaunchEvent,
  LaunchEventScheduleItem,
  LaunchSchedulePlatform,
} from '@/lib/init/types'
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

function parseDateOnly(isoDate: string): Date {
  const [year, month, day] = isoDate.split('-').map(Number)
  return new Date(year, month - 1, day)
}

function getDefaultScheduleDay(event: LaunchEvent): number {
  const liveDay = event.days.find((day) => day.isLive)?.day
  if (liveDay) return liveDay

  const days = event.days.map((day) => day.day).sort((a, b) => a - b)
  if (days.length === 0) return 1

  const now = new Date()
  const start = parseDateOnly(event.startDate)
  const end = parseDateOnly(event.endDate)
  end.setHours(23, 59, 59, 999)

  if (now < start) return days[0]
  if (now > end) return days[days.length - 1]

  const dayIndex =
    Math.floor((now.getTime() - start.getTime()) / (24 * 60 * 60 * 1000)) + 1
  const maxDay = days[days.length - 1]
  return Math.min(Math.max(dayIndex, days[0]), maxDay)
}

interface EventSchedulePanelProps {
  event: LaunchEvent
}

function ScheduleRow({ item }: { item: LaunchEventScheduleItem }) {
  const meta = PLATFORM_META[item.platform]
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
          {item.isLive ? (
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
            item.isLive &&
              'bg-[color-mix(in_srgb,var(--brand-cta)_5%,transparent)]',
          )}
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
        item.isLive && 'bg-[color-mix(in_srgb,var(--brand-cta)_5%,transparent)]',
      )}
    >
      {content}
    </li>
  )
}

export function EventSchedulePanel({ event }: EventSchedulePanelProps) {
  const scheduleDays = useMemo(() => {
    const fromDays = event.days.map((day) => day.day)
    const fromSchedule = event.schedule.map((item) => item.day)
    return [...new Set([...fromDays, ...fromSchedule])].sort((a, b) => a - b)
  }, [event.days, event.schedule])

  const [selectedDay, setSelectedDay] = useState(() =>
    getDefaultScheduleDay(event),
  )

  const selectedDayInfo = event.days.find((day) => day.day === selectedDay)
  const dayEvents = event.schedule.filter((item) => item.day === selectedDay)

  const selectedIndex = scheduleDays.indexOf(selectedDay)
  const canGoPrevious = selectedIndex > 0
  const canGoNext = selectedIndex >= 0 && selectedIndex < scheduleDays.length - 1

  if (event.schedule.length === 0 || scheduleDays.length === 0) return null

  return (
    <div
      className={cn(
        CARD_SHELL,
        'lg:sticky lg:self-start',
        event.liveBanner ? 'lg:top-32' : 'lg:top-20',
      )}
    >
      <div className="px-6 py-4">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h3 className="text-[15px] font-semibold text-foreground">Schedule</h3>
            <p className="mt-1 text-[13px] text-muted-foreground">
              {selectedDayInfo?.isLive
                ? "Today's sessions"
                : 'YouTube, Discord, and Reddit sessions'}
            </p>
          </div>
          {scheduleDays.length > 1 ? (
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
                  setSelectedDay(scheduleDays[selectedIndex - 1])
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
                  setSelectedDay(scheduleDays[selectedIndex + 1])
                }}
              >
                <ChevronRight className="size-4" />
              </Button>
            </div>
          ) : null}
        </div>
        <p className="mt-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          Day {selectedDay}
          {selectedDayInfo?.dateLabel ? ` · ${selectedDayInfo.dateLabel}` : null}
          {selectedDayInfo?.title ? ` · ${selectedDayInfo.title}` : null}
        </p>
      </div>
      <div className="border-t border-border" />
      {dayEvents.length > 0 ? (
        <ol className="divide-y divide-border">
          {dayEvents.map((item) => (
            <ScheduleRow key={item.id} item={item} />
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
