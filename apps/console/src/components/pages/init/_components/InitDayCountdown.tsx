import { useMemo } from 'react'
import { resolveInitDayUnlockDate } from '@/lib/init/dates'
import { useInitDayCountdown } from '@/lib/init/use-init-day-countdown'
import { cn } from '@/lib/utils'

interface InitDayCountdownProps {
  eventStartDate: string
  dayNumber: number
  className?: string
  size?: 'sm' | 'md'
}

export function InitDayCountdown({
  eventStartDate,
  dayNumber,
  className,
  size = 'md',
}: InitDayCountdownProps) {
  const unlockAt = useMemo(
    () => resolveInitDayUnlockDate(eventStartDate, dayNumber),
    [eventStartDate, dayNumber],
  )
  const { label } = useInitDayCountdown(unlockAt)

  return (
    <p
      className={cn(
        'font-mono tabular-nums text-foreground',
        size === 'sm' ? 'text-[12px]' : 'text-[15px] font-semibold',
        className,
      )}
      aria-live="polite"
    >
      {label}
    </p>
  )
}
