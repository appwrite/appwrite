import { CalendarDays, ChevronDown } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useT } from '@/lib/i18n/translate'
import {
  ANALYTICS_DATE_RANGES,
  type AnalyticsDateRange,
} from '@/lib/react-query/hooks'

const RANGE_LABELS: Record<AnalyticsDateRange, string> = {
  '24h': 'Last 24 hours',
  '7d': 'Last 7 days',
  '30d': 'Last 30 days',
  '90d': 'Last 90 days',
}

interface DateRangeSelectProps {
  value: AnalyticsDateRange
  onChange: (value: AnalyticsDateRange) => void
  disabled?: boolean
  className?: string
}

/**
 * The Analytics API accepts a `dateRange` shorthand (e.g. `7d`, `30d`) rather
 * than an arbitrary from/to pair, so this replaces the generic calendar picker.
 */
export function DateRangeSelect({
  value,
  onChange,
  disabled = false,
  className,
}: DateRangeSelectProps) {
  const t = useT()

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
            {t(RANGE_LABELS[value])}
          </span>
          <ChevronDown className="h-3.5 w-3.5 opacity-50" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-[180px]">
        {ANALYTICS_DATE_RANGES.map((range) => (
          <DropdownMenuItem
            key={range}
            onClick={() => onChange(range)}
            className={cn(
              'cursor-pointer text-[12px]',
              value === range && 'bg-accent',
            )}
          >
            {t(RANGE_LABELS[range])}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
