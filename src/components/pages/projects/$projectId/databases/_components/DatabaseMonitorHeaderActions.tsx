import { endOfDay, startOfDay, subDays } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { RefreshButton } from '@/components/global/shared/RefreshButton'

export function getDefaultMonitorDateRange(): DateRange {
  return {
    from: startOfDay(subDays(new Date(), 29)),
    to: endOfDay(new Date()),
  }
}

type DatabaseMonitorHeaderActionsProps = {
  dateRange: DateRange
  onDateRangeChange: (range: DateRange | undefined) => void
  onRefresh: () => void
}

export function DatabaseMonitorHeaderActions({
  dateRange,
  onDateRangeChange,
  onRefresh,
}: DatabaseMonitorHeaderActionsProps) {
  return (
    <div className="flex shrink-0 items-center gap-2 @[560px]:gap-3">
      <DateRangePicker
        dateRange={dateRange}
        onDateRangeChange={onDateRangeChange}
        className="h-7 min-w-[120px] text-[12px] @[560px]:min-w-[160px]"
      />
      <RefreshButton onClick={onRefresh} className="h-7 w-7" />
    </div>
  )
}
