import { scrollToInitDayCard } from '@/lib/init/scroll-to-day-card'
import type { LaunchEventDayView } from '@/lib/init/types'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { buildInitDayPreviewActivity } from '@/lib/init/init-presence-day-activity'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

interface InitCollapsedDayNavProps {
  days: LaunchEventDayView[]
  activeDay: number
  currentDay: number
}

export function InitCollapsedDayNav({ days, activeDay, currentDay }: InitCollapsedDayNavProps) {
  const { setTransientActivity } = useInitPresenceActivity()

  if (days.length === 0) return null

  return (
    <nav
      className="flex min-w-0 justify-center gap-0.5 overflow-x-auto px-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      aria-label="Init days"
    >
      {days.map((day) => {
        const isActive = day.day === activeDay
        return (
          <Button
            key={day.day}
            type="button"
            variant="ghost"
            size="sm"
            className={cn(
              'h-8 shrink-0 rounded-md px-2.5 text-[12px] font-medium text-muted-foreground',
              isActive && 'bg-accent text-foreground',
            )}
            aria-current={isActive ? 'true' : undefined}
            onClick={() => scrollToInitDayCard(day.day)}
            onMouseEnter={() =>
              setTransientActivity(buildInitDayPreviewActivity(day, currentDay))
            }
            onMouseLeave={() => setTransientActivity(null)}
            onFocus={() => setTransientActivity(buildInitDayPreviewActivity(day, currentDay))}
            onBlur={() => setTransientActivity(null)}
          >
            Day {day.day}
          </Button>
        )
      })}
    </nav>
  )
}
