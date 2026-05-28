import type { MouseEvent } from 'react'
import { Button } from '@/components/ui/button'
import {
  getInitDayCardId,
  scrollToInitDayCard,
} from '@/lib/init/scroll-to-day-card'
import type { LaunchEventDayView } from '@/lib/init/types'
import { Link2 } from 'lucide-react'

interface InitDayCardHeaderNavProps {
  day: LaunchEventDayView
}

export function InitDayCardHeaderNav({ day }: InitDayCardHeaderNavProps) {
  const hash = `#${getInitDayCardId(day.day)}`

  const handleClick = (event: MouseEvent<HTMLAnchorElement>) => {
    event.preventDefault()
    scrollToInitDayCard(day.day)
    window.history.replaceState(null, '', hash)
  }

  return (
    <Button
      asChild
      variant="ghost"
      size="icon"
      className="size-6 shrink-0 text-muted-foreground"
    >
      <a href={hash} aria-label={`Link to day ${day.day}`} onClick={handleClick}>
        <Link2 className="size-3.5" aria-hidden />
      </a>
    </Button>
  )
}
