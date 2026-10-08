import type { DateRange } from 'react-day-picker'
import { isSameDay, isSameYear } from 'date-fns'
import { ArrowLeftRight, ChevronDown } from 'lucide-react'
import { cn } from '@/lib/utils'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { useT } from '@/lib/i18n/translate'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import type {
  AnalyticsCompareMode,
  AnalyticsRange,
} from '@/lib/react-query/hooks'

export const COMPARE_MODE_OPTIONS: {
  value: AnalyticsCompareMode
  label: string
}[] = [
  { value: 'off', label: 'No comparison' },
  { value: 'previous', label: 'Previous period' },
  { value: 'year', label: 'Same period last year' },
  { value: 'custom', label: 'Custom range' },
]

export function compareModeLabel(mode: AnalyticsCompareMode): string {
  return (
    COMPARE_MODE_OPTIONS.find((option) => option.value === mode)?.label ??
    'Previous period'
  )
}

/** Compact human label for a comparison window, e.g. "Sep 7 – Oct 6, 2026". */
export function formatAnalyticsRangeLabel(range: AnalyticsRange): string {
  const from = new Date(range.startAt)
  const to = new Date(range.endAt)
  if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) return ''
  if (isSameDay(from, to)) return formatLocalizedDate(from, 'MMM d, yyyy')
  if (isSameYear(from, to)) {
    return `${formatLocalizedDate(from, 'MMM d')} – ${formatLocalizedDate(to, 'MMM d, yyyy')}`
  }
  return `${formatLocalizedDate(from, 'MMM d, yyyy')} – ${formatLocalizedDate(to, 'MMM d, yyyy')}`
}

type CompareControlProps = {
  mode: AnalyticsCompareMode
  onModeChange: (mode: AnalyticsCompareMode) => void
  customDateRange: DateRange | undefined
  onCustomDateRangeChange: (range: DateRange | undefined) => void
  /** Resolved comparison window, shown as a hint in the menu. */
  comparisonRange: AnalyticsRange | null
  className?: string
}

export function CompareControl({
  mode,
  onModeChange,
  customDateRange,
  onCustomDateRangeChange,
  comparisonRange,
  className,
}: CompareControlProps) {
  const t = useT()
  const isOn = mode !== 'off'

  return (
    <div className={cn('flex items-center gap-2', className)}>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="outline"
            size="sm"
            className={cn(
              'h-9 gap-1.5 text-[12px] font-medium',
              !isOn && 'text-muted-foreground',
            )}
            aria-label={t('Compare')}
          >
            <ArrowLeftRight className="h-3.5 w-3.5 shrink-0" />
            <span className="hidden sm:inline">
              {isOn ? `${t('vs')} ${t(compareModeLabel(mode))}` : t('Compare')}
            </span>
            <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-[240px]">
          <DropdownMenuLabel className="text-[11px] text-muted-foreground">
            {t('Compare with')}
          </DropdownMenuLabel>
          <DropdownMenuSeparator />
          <DropdownMenuRadioGroup
            value={mode}
            onValueChange={(value) =>
              onModeChange(value as AnalyticsCompareMode)
            }
          >
            {COMPARE_MODE_OPTIONS.map((option) => (
              <DropdownMenuRadioItem
                key={option.value}
                value={option.value}
                className="text-[12px]"
              >
                {t(option.label)}
              </DropdownMenuRadioItem>
            ))}
          </DropdownMenuRadioGroup>
          {comparisonRange ? (
            <>
              <DropdownMenuSeparator />
              <p className="px-2 py-1.5 text-[11px] text-muted-foreground">
                {formatAnalyticsRangeLabel(comparisonRange)}
              </p>
            </>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>

      {mode === 'custom' ? (
        <DateRangePicker
          dateRange={customDateRange}
          onDateRangeChange={onCustomDateRangeChange}
          className="h-9 shrink-0"
        />
      ) : null}
    </div>
  )
}
