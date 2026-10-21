import { Info } from 'lucide-react'
import { GB_HOURS_UNIT_TOOLTIP } from '@/lib/usage/format-metric'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

interface GbHoursUnitInfoProps {
  className?: string
  iconClassName?: string
  /** Use a span trigger when rendered inside another button (e.g. overview tabs). */
  nested?: boolean
}

export function GbHoursUnitInfo({
  className,
  iconClassName,
  nested = false,
}: GbHoursUnitInfoProps) {
  const t = useT()
  const triggerClassName = cn(
    'inline-flex shrink-0 text-muted-foreground transition-colors hover:text-foreground',
    className,
  )

  return (
    <Tooltip delayDuration={0}>
      <TooltipTrigger asChild>
        {nested ? (
          <span
            className={triggerClassName}
            onClick={(event) => event.stopPropagation()}
            onPointerDown={(event) => event.stopPropagation()}
            aria-label={t('About GBH')}
            role="img"
          >
            <Info className={cn('h-3 w-3', iconClassName)} />
          </span>
        ) : (
          <button
            type="button"
            className={triggerClassName}
            onClick={(event) => event.stopPropagation()}
            onPointerDown={(event) => event.stopPropagation()}
            aria-label={t('About GBH')}
          >
            <Info className={cn('h-3 w-3', iconClassName)} />
          </button>
        )}
      </TooltipTrigger>
      <TooltipContent
        side="top"
        className="max-w-xs text-[12px] leading-relaxed"
      >
        <p>{t(GB_HOURS_UNIT_TOOLTIP)}</p>
      </TooltipContent>
    </Tooltip>
  )
}
