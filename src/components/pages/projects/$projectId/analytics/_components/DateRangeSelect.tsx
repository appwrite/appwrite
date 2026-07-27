import { useState } from 'react'
import { endOfDay, startOfDay, subDays } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { CalendarDays, ChevronDown } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { useT } from '@/lib/i18n/translate'
import {
  ANALYTICS_DATE_RANGES,
  DEFAULT_ANALYTICS_DATE_RANGE,
  type AnalyticsDateRange,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'

const RANGE_LABELS: Record<AnalyticsDateRange, string> = {
  '24h': 'Last 24 hours',
  '7d': 'Last 7 days',
  '30d': 'Last 30 days',
  '90d': 'Last 90 days',
}

interface DateRangeSelectProps {
  value: AnalyticsRange
  onChange: (value: AnalyticsRange) => void
  disabled?: boolean
  className?: string
}

/**
 * Shorthand ranges map onto the API's `dateRange`; a custom window maps onto
 * explicit `startAt`/`endAt` bounds.
 */
export function DateRangeSelect({
  value,
  onChange,
  disabled = false,
  className,
}: DateRangeSelectProps) {
  const t = useT()
  const [customRange, setCustomRange] = useState<DateRange | undefined>(() =>
    value.kind === 'custom'
      ? { from: new Date(value.startAt), to: new Date(value.endAt) }
      : { from: startOfDay(subDays(new Date(), 29)), to: endOfDay(new Date()) },
  )

  const handleCustomChange = (range: DateRange | undefined) => {
    setCustomRange(range)
    if (range?.from && range?.to) {
      onChange({
        kind: 'custom',
        startAt: startOfDay(range.from).toISOString(),
        endAt: endOfDay(range.to).toISOString(),
      })
    }
  }

  if (value.kind === 'custom') {
    return (
      <div className={cn('flex items-center gap-2', className)}>
        <DateRangePicker
          dateRange={customRange}
          onDateRangeChange={handleCustomChange}
        />
        <Button
          variant="ghost"
          size="sm"
          className="h-8 text-[12px]"
          disabled={disabled}
          onClick={() =>
            onChange({
              kind: 'shorthand',
              dateRange: DEFAULT_ANALYTICS_DATE_RANGE,
            })
          }
        >
          {t('Reset')}
        </Button>
      </div>
    )
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          disabled={disabled}
          className={cn('h-8 gap-1.5 text-[12px] font-medium', className)}
        >
          <CalendarDays className="h-3.5 w-3.5" />
          <span className="min-w-[92px] text-start">
            {t(RANGE_LABELS[value.dateRange])}
          </span>
          <ChevronDown className="h-3.5 w-3.5 opacity-50" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-[180px]">
        {ANALYTICS_DATE_RANGES.map((range) => (
          <DropdownMenuItem
            key={range}
            onClick={() => onChange({ kind: 'shorthand', dateRange: range })}
            className={cn(
              'cursor-pointer text-[12px]',
              value.dateRange === range && 'bg-accent',
            )}
          >
            {t(RANGE_LABELS[range])}
          </DropdownMenuItem>
        ))}
        <DropdownMenuSeparator />
        <DropdownMenuItem
          className="cursor-pointer text-[12px]"
          onClick={() => handleCustomChange(customRange)}
        >
          {t('Custom range')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
