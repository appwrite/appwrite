import { Info } from 'lucide-react'
import { GB_HOURS_UNIT_TOOLTIP } from '@/lib/usage/format-metric'
import { cn } from '@/lib/utils'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

interface GbHoursUnitInfoProps {
  className?: string
  iconClassName?: string
}

export function GbHoursUnitInfo({
  className,
  iconClassName,
}: GbHoursUnitInfoProps) {
  return (
    <Tooltip delayDuration={0}>
      <TooltipTrigger asChild>
        <button
          type="button"
          className={cn(
            'inline-flex shrink-0 text-muted-foreground transition-colors hover:text-foreground',
            className,
          )}
          onClick={(event) => event.stopPropagation()}
          onPointerDown={(event) => event.stopPropagation()}
          aria-label="About GBH"
        >
          <Info className={cn('h-3 w-3', iconClassName)} />
        </button>
      </TooltipTrigger>
      <TooltipContent
        side="top"
        className="max-w-xs text-[12px] leading-relaxed"
      >
        <p>{GB_HOURS_UNIT_TOOLTIP}</p>
      </TooltipContent>
    </Tooltip>
  )
}
