import { useEffect, useMemo, useState } from 'react'
import type { InitDisplayEvent } from '@/lib/init/types'
import { isLaunchEventDayLocked } from '@/lib/init/types'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { buildInitCheckingScheduleActivity } from '@/lib/init/init-presence-activity'
import { Button } from '@/components/ui/button'
import { InitScheduleRow } from './InitScheduleRow'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { cn } from '@/lib/utils'

const CARD_SHELL =
  'rounded-xl border border-border bg-card/50 overflow-hidden'

interface EventSchedulePanelProps {
  event: InitDisplayEvent
  /** When true, sticky positioning is handled by the parent column wrapper. */
  embedded?: boolean
}

export function EventSchedulePanel({
  event,
  embedded = false,
}: EventSchedulePanelProps) {
  const scheduleDays = useMemo(() => {
    return event.days.map((day) => day.day).sort((a, b) => a - b)
  }, [event.days])

  const defaultDay =
    event.currentDay > 0 && scheduleDays.includes(event.currentDay)
      ? event.currentDay
      : scheduleDays[0] ?? 1

  const [selectedDay, setSelectedDay] = useState(defaultDay)
  const { setTransientActivity } = useInitPresenceActivity()

  useEffect(() => {
    setSelectedDay(defaultDay)
  }, [defaultDay])

  const selectedDayInfo = event.days.find((day) => day.day === selectedDay)
  const dayEvents = event.schedule.filter((item) => item.day === selectedDay)

  const selectedIndex = scheduleDays.indexOf(selectedDay)
  const canGoPrevious = selectedIndex > 0
  const canGoNext =
    selectedIndex >= 0 && selectedIndex < scheduleDays.length - 1

  if (event.schedule.length === 0 || scheduleDays.length === 0) {
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
                  : 'YouTube streams and Reddit AMAs'}
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
            <InitScheduleRow
              key={item.id}
              event={event}
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
