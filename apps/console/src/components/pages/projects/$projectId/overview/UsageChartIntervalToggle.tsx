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
  getUsageChartIntervalDisabledReasonDetails,
  type UsageChartInterval,
  type UsageChartIntervalDisabledReasonDetails,
} from '@/lib/usage/chart-interval'
import { useT, type Translator } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const TOGGLE_ITEM_CLASS =
  'h-full w-full min-w-[2.75rem] px-2 text-[12px] font-medium text-muted-foreground hover:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground data-[state=on]:hover:bg-secondary data-[state=on]:hover:text-secondary-foreground'

const TOGGLE_CELL_CLASS = 'flex min-w-0 flex-1 h-full'

function formatUsageChartIntervalDisabledReason(
  details: UsageChartIntervalDisabledReasonDetails | undefined,
  t: Translator,
): string | undefined {
  if (!details) return undefined

  if (details.kind === 'hours') {
    return `${t('Use a date range of')} ${details.maxHours} ${t('hours or less for this interval.')}`
  }

  return `${t('Use a date range of')} ${details.maxDays} ${t('days or less for this interval.')}`
}

type UsageChartIntervalToggleProps = {
  value: UsageChartInterval
  onValueChange: (value: UsageChartInterval) => void
  dateRange: DateRange | undefined
  /** When set, only plan-allowed intervals are shown (`usageLogsIntervals`). */
  allowedIntervals?: readonly UsageChartInterval[]
  className?: string
}

export function UsageChartIntervalToggle({
  value,
  onValueChange,
  dateRange,
  allowedIntervals,
  className,
}: UsageChartIntervalToggleProps) {
  const t = useT()
  const options = allowedIntervals?.length
    ? USAGE_CHART_INTERVAL_OPTIONS.filter((option) =>
        allowedIntervals.includes(option.value),
      )
    : USAGE_CHART_INTERVAL_OPTIONS
  const optionCount = options.length

  if (optionCount <= 1) {
    return null
  }

  return (
    <TooltipProvider delayDuration={300}>
      <ToggleGroup
        type="single"
        variant="outline"
        size="default"
        value={value}
        onValueChange={(next) => {
          if (!next) return
          if (options.some((option) => option.value === next)) {
            onValueChange(next as UsageChartInterval)
          }
        }}
        className={cn('h-9 w-fit shrink-0 gap-0', className)}
        aria-label={t('Chart interval')}
      >
        {options.map((option, index) => {
          const isFirst = index === 0
          const isLast = index === optionCount - 1
          const disabledReason = formatUsageChartIntervalDisabledReason(
            getUsageChartIntervalDisabledReasonDetails(
              option.value,
              dateRange,
            ),
            t,
          )

          const item = (
            <ToggleGroupItem
              value={option.value}
              disabled={!!disabledReason}
              className={cn(
                TOGGLE_ITEM_CLASS,
                '!rounded-none shadow-none',
                isFirst && '!rounded-s-md !border-s',
                isLast && '!rounded-e-md',
                !isFirst && '!border-s-0',
              )}
            >
              {t(option.label)}
            </ToggleGroupItem>
          )

          const cell = (
            <span
              className={cn(
                TOGGLE_CELL_CLASS,
                disabledReason && 'cursor-not-allowed',
              )}
            >
              {item}
            </span>
          )

          if (!disabledReason) {
            return (
              <span key={option.value} className={TOGGLE_CELL_CLASS}>
                {item}
              </span>
            )
          }

          return (
            <Tooltip key={option.value}>
              <TooltipTrigger asChild>{cell}</TooltipTrigger>
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
