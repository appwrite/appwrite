import type { LaunchEventDayLocked } from '@/lib/init/types'
import { cn } from '@/lib/utils'
import { Lock } from 'lucide-react'

const CARD_SHELL =
  'rounded-xl border border-border bg-card/50 overflow-hidden'

interface LockedDayDetailCardProps {
  day: LaunchEventDayLocked
}

export function LockedDayDetailCard({ day }: LockedDayDetailCardProps) {
  return (
    <article
      id={`day-${day.day}`}
      className={cn(CARD_SHELL, 'scroll-mt-28')}
      aria-label={`Day ${day.day} locked`}
    >
      <header className="border-b border-border px-6 py-3 text-center">
        <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
          Day {day.day} / {day.weekdayLabel}
        </p>
      </header>

      <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span className="flex size-12 items-center justify-center rounded-full border border-border bg-muted/40 text-muted-foreground">
          <Lock className="size-5" aria-hidden />
        </span>
        <p className="mt-4 text-[15px] font-semibold text-foreground">Coming soon</p>
        <p className="mt-2 max-w-sm text-[13px] text-muted-foreground">
          This launch unlocks on {day.dateLabel}. Check back then for announcements,
          resources, and sessions.
        </p>
      </div>
    </article>
  )
}
