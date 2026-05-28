import type { InitDisplayEvent } from '@/lib/init/types'
import { Badge } from '@/components/ui/badge'

interface InitRecapBannerProps {
  event: InitDisplayEvent
}

export function InitRecapBanner({ event }: InitRecapBannerProps) {
  if (!event.isRecapMode) return null

  const message =
    event.recap?.bannerMessage ??
    `${event.dateRangeLabel} has ended. Browse the full recap below.`

  return (
    <div className="shrink-0 border-b border-border bg-background">
      <div className="mx-auto flex max-w-7xl items-center gap-2 px-4 py-2.5 sm:px-6">
        <Badge variant="info" className="text-[10px] shrink-0">
          Recap
        </Badge>
        <span className="text-[13px] text-muted-foreground">{message}</span>
      </div>
    </div>
  )
}
