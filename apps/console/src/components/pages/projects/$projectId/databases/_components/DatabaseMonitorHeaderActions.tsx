import type { DateRange } from 'react-day-picker'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { UsageChartIntervalToggle } from '@/components/pages/projects/$projectId/overview/UsageChartIntervalToggle'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { getDefaultMonitorChartDateRange } from '@/lib/usage/usage-date-range'

export function getDefaultMonitorDateRange(): DateRange {
  return getDefaultMonitorChartDateRange()
}

type DatabaseMonitorHeaderActionsProps = {
  dateRange: DateRange
  dateRangePresetId?: string | null
  onDateRangeChange: (range: DateRange | undefined) => void
  chartInterval?: UsageChartInterval
  onChartIntervalChange?: (interval: UsageChartInterval) => void
  allowedIntervals?: readonly UsageChartInterval[]
  onRefresh: () => void
  isRefreshing?: boolean
}

export function DatabaseMonitorHeaderActions({
  dateRange,
  dateRangePresetId = null,
  onDateRangeChange,
  chartInterval,
  onChartIntervalChange,
  allowedIntervals,
  onRefresh,
  isRefreshing,
}: DatabaseMonitorHeaderActionsProps) {
  return (
    <div className="flex shrink-0 items-center gap-2 @[560px]:gap-3">
      {chartInterval && onChartIntervalChange ? (
        <UsageChartIntervalToggle
          value={chartInterval}
          onValueChange={onChartIntervalChange}
          dateRange={dateRange}
          allowedIntervals={allowedIntervals}
          className="h-9"
        />
      ) : null}
      <DateRangePicker
        dateRange={dateRange}
        onDateRangeChange={onDateRangeChange}
        presetId={dateRangePresetId}
        className="h-9"
      />
      <RefreshButton
        onClick={onRefresh}
        isRefreshing={isRefreshing}
      />
    </div>
  )
}
