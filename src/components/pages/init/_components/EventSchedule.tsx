import type { LaunchEvent, LaunchEventDay } from '@/lib/init/types'
import { cn } from '@/lib/utils'
import { Badge } from '@/components/ui/badge'

const CARD_SHELL =
  'rounded-xl border border-border bg-card/50 overflow-hidden'

interface EventScheduleProps {
  event: LaunchEvent
  fullWidth?: boolean
}

function ScheduleDayCard({
  day,
  fullWidth = false,
}: {
  day: LaunchEventDay
  fullWidth?: boolean
}) {
  return (
    <button
      type="button"
      className={cn(
        'flex flex-col rounded-xl border bg-card/50 p-4 text-left transition-colors',
        'cursor-pointer hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        fullWidth ? 'min-w-0 w-full' : 'min-w-[200px] max-w-[220px] shrink-0',
        day.isLive
          ? 'border-[color-mix(in_srgb,var(--brand-cta)_45%,var(--border))]'
          : 'border-border hover:border-border',
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          Day {day.day} · {day.dateLabel}
        </p>
        {day.isLive ? (
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
            <ScheduleDayCard key={day.day} day={day} fullWidth />
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
            <ScheduleDayCard key={day.day} day={day} />
          ))}
        </div>
      </div>
    </section>
  )
}
