import type { DateRange } from 'react-day-picker'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  USAGE_CHART_INTERVAL_OPTIONS,
  getUsageChartIntervalDisabledReason,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import { cn } from '@/lib/utils'

const TOGGLE_ITEM_CLASS =
  'h-9 min-w-[2.75rem] px-2 text-[12px] font-medium text-muted-foreground hover:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground data-[state=on]:hover:bg-secondary data-[state=on]:hover:text-secondary-foreground'

type UsageChartIntervalToggleProps = {
  value: UsageChartInterval
  onValueChange: (value: UsageChartInterval) => void
  dateRange: DateRange | undefined
  className?: string
}

export function UsageChartIntervalToggle({
  value,
  onValueChange,
  dateRange,
  className,
}: UsageChartIntervalToggleProps) {
  return (
    <TooltipProvider delayDuration={300}>
      <ToggleGroup
        type="single"
        variant="outline"
        size="default"
        value={value}
        onValueChange={(next) => {
          if (!next) return
          if (
            USAGE_CHART_INTERVAL_OPTIONS.some((option) => option.value === next)
          ) {
            onValueChange(next as UsageChartInterval)
          }
        }}
        className={cn('shrink-0', className)}
        aria-label="Chart interval"
      >
        {USAGE_CHART_INTERVAL_OPTIONS.map((option) => {
          const disabledReason = getUsageChartIntervalDisabledReason(
            option.value,
            dateRange,
          )
          const item = (
            <ToggleGroupItem
              key={option.value}
              value={option.value}
              disabled={!!disabledReason}
              className={cn(TOGGLE_ITEM_CLASS, className)}
            >
              {option.label}
            </ToggleGroupItem>
          )

          if (!disabledReason) {
            return item
          }

          return (
            <Tooltip key={option.value}>
              <TooltipTrigger asChild>
                <span className={cn('inline-flex', className)}>{item}</span>
              </TooltipTrigger>
              <TooltipContent side="bottom" className="max-w-[220px]">
                {disabledReason}
              </TooltipContent>
            </Tooltip>
          )
        })}
      </ToggleGroup>
    </TooltipProvider>
  )
}
