import type { InitDisplayEvent, LaunchEventDayView } from '@/lib/init/types'
import { isLaunchEventDayLocked } from '@/lib/init/types'
import { scrollToInitDayCard } from '@/lib/init/scroll-to-day-card'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { buildInitDayPreviewActivity } from '@/lib/init/init-presence-day-activity'
import { cn } from '@/lib/utils'
import { Badge } from '@/components/ui/badge'
import { InitDayCountdown } from './InitDayCountdown'
import { Lock } from 'lucide-react'

const CARD_SHELL =
  'rounded-xl border border-border bg-card/50 overflow-hidden'

interface EventScheduleProps {
  event: InitDisplayEvent
  fullWidth?: boolean
}

function LockedScheduleDayCard({
  day,
  eventStartDate,
  currentDay,
  fullWidth = false,
}: {
  day: Extract<LaunchEventDayView, { isLocked: true }>
  eventStartDate: string
  currentDay: number
  fullWidth?: boolean
}) {
  const { setTransientActivity } = useInitPresenceActivity()

  return (
    <button
      type="button"
      onClick={() => scrollToInitDayCard(day.day)}
      onMouseEnter={() => setTransientActivity(buildInitDayPreviewActivity(day, currentDay))}
      onMouseLeave={() => setTransientActivity(null)}
      onFocus={() => setTransientActivity(buildInitDayPreviewActivity(day, currentDay))}
      onBlur={() => setTransientActivity(null)}
      className={cn(
        'flex flex-col rounded-xl border border-dashed border-border bg-muted/20 p-4 text-start transition-colors',
        'cursor-pointer hover:bg-muted/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        fullWidth ? 'min-w-0 w-full' : 'min-w-[200px] max-w-[220px] shrink-0',
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          Day {day.day} · {day.dateLabel}
        </p>
        <Lock className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
      </div>
      <p className="mt-3 text-[13px] font-medium text-muted-foreground">Coming soon</p>
      <InitDayCountdown
        eventStartDate={eventStartDate}
        dayNumber={day.day}
        size="sm"
        className="mt-2"
      />
      <p className="mt-2 text-[12px] leading-normal text-muted-foreground/80">
        Unlocks on {day.dateLabel}
      </p>
    </button>
  )
}

function ScheduleDayCard({
  day,
  currentDay,
  isRecapMode = false,
  fullWidth = false,
}: {
  day: Exclude<LaunchEventDayView, { isLocked: true }>
  currentDay: number
  isRecapMode?: boolean
  fullWidth?: boolean
}) {
  const { setTransientActivity } = useInitPresenceActivity()

  return (
    <button
      type="button"
      onClick={() => scrollToInitDayCard(day.day)}
      onMouseEnter={() => setTransientActivity(buildInitDayPreviewActivity(day, currentDay))}
      onMouseLeave={() => setTransientActivity(null)}
      onFocus={() => setTransientActivity(buildInitDayPreviewActivity(day, currentDay))}
      onBlur={() => setTransientActivity(null)}
      className={cn(
        'flex flex-col rounded-xl border bg-card/50 p-4 text-start transition-colors',
        'cursor-pointer hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        fullWidth ? 'min-w-0 w-full' : 'min-w-[200px] max-w-[220px] shrink-0',
        !isRecapMode &&
          day.isLive &&
          'border-[color-mix(in_srgb,var(--brand-cta)_45%,var(--border))]',
        isRecapMode || !day.isLive
          ? 'border-border hover:border-border'
          : undefined,
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          Day {day.day} · {day.dateLabel}
        </p>
        {!isRecapMode && day.isLive ? (
          <Badge variant="error" className="text-[10px] shrink-0">
            Live
          </Badge>
        ) : null}
      </div>

      <h4 className="mt-3 text-[15px] font-semibold leading-none text-foreground">
        {day.title}
      </h4>
      <p className="mt-3 min-h-[2lh] line-clamp-2 text-[12px] leading-normal text-muted-foreground">
        {day.description}
      </p>
    </button>
  )
}

function DayPickerCard({
  day,
  eventStartDate,
  currentDay,
  isRecapMode = false,
  fullWidth = false,
}: {
  day: LaunchEventDayView
  eventStartDate: string
  currentDay: number
  isRecapMode?: boolean
  fullWidth?: boolean
}) {
  if (isLaunchEventDayLocked(day)) {
    return (
      <LockedScheduleDayCard
        day={day}
        eventStartDate={eventStartDate}
        currentDay={currentDay}
        fullWidth={fullWidth}
      />
    )
  }

  return (
    <ScheduleDayCard
      day={day}
      currentDay={currentDay}
      isRecapMode={isRecapMode}
      fullWidth={fullWidth}
    />
  )
}

export function EventSchedule({ event, fullWidth = false }: EventScheduleProps) {
  if (event.days.length === 0) {
    return (
      <div className={CARD_SHELL}>
        <div className="px-6 py-8 text-center">
          <p className="text-[13px] text-muted-foreground">
            The schedule will be announced closer to the event.
          </p>
        </div>
      </div>
    )
  }

  if (fullWidth) {
    return (
      <section>
        <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
          {event.days.map((day) => (
            <DayPickerCard
              key={day.day}
              day={day}
              eventStartDate={event.startDate}
              currentDay={event.currentDay}
              isRecapMode={event.isRecapMode}
              fullWidth
            />
          ))}
        </div>
      </section>
    )
  }

  return (
    <section>
      <div className="-mx-1 overflow-x-auto px-1 pb-1">
        <div className="flex gap-3">
          {event.days.map((day) => (
            <DayPickerCard
              key={day.day}
              day={day}
              eventStartDate={event.startDate}
              currentDay={event.currentDay}
              isRecapMode={event.isRecapMode}
            />
          ))}
        </div>
      </div>
    </section>
  )
}
