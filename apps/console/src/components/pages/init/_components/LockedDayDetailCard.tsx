import type { LaunchEventDayLocked } from '@/lib/init/types'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { buildInitDayPreviewActivity } from '@/lib/init/init-presence-day-activity'
import { getInitDayCardId } from '@/lib/init/scroll-to-day-card'
import { InitDayCardHeaderNav } from './InitDayCardHeaderNav'
import { InitDayCountdown } from './InitDayCountdown'
import { cn } from '@/lib/utils'
import { Lock } from 'lucide-react'

const CARD_SHELL =
  'rounded-xl border border-border bg-card/50 overflow-hidden'

interface LockedDayDetailCardProps {
  day: LaunchEventDayLocked
  eventStartDate: string
  currentDay: number
}

export function LockedDayDetailCard({
  day,
  eventStartDate,
  currentDay,
}: LockedDayDetailCardProps) {
  const { setTransientActivity } = useInitPresenceActivity()

  return (
    <article
      id={getInitDayCardId(day.day)}
      className={cn(CARD_SHELL, 'scroll-mt-28')}
      aria-label={`Day ${day.day} locked`}
      onMouseEnter={() => setTransientActivity(buildInitDayPreviewActivity(day, currentDay))}
      onMouseLeave={() => setTransientActivity(null)}
    >
      <header className="grid grid-cols-[1fr_auto_1fr] items-center border-b border-border px-6 py-3">
        <span aria-hidden />
        <p className="text-center text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
          Day {day.day} / {day.weekdayLabel}
        </p>
        <div className="flex justify-end">
          <InitDayCardHeaderNav day={day} />
        </div>
      </header>

      <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span className="flex size-12 items-center justify-center rounded-full border border-border bg-muted/40 text-muted-foreground">
          <Lock className="size-5" aria-hidden />
        </span>
        <p className="mt-4 text-[15px] font-semibold text-foreground">Coming soon</p>
        <InitDayCountdown
          eventStartDate={eventStartDate}
          dayNumber={day.day}
          className="mt-3"
        />
        <p className="mt-3 max-w-sm text-[13px] text-muted-foreground">
          This launch unlocks on {day.dateLabel}. Check back then for announcements,
          resources, and sessions.
        </p>
      </div>
    </article>
  )
}
