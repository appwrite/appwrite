import type { DateRange } from 'react-day-picker'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  resolveUsageChartIntervalForRange,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import {
  getStableUsageChartDateRange,
  parseUsageChartDateRange,
  serializeUsageChartDateRange,
} from '@/lib/usage/usage-date-range'
import {
  parseUsageChartDateRangeFromPrefs,
  parseUsageChartIntervalFromPrefs,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

export type UsageChartFilters = {
  dateRange: DateRange
  chartInterval: UsageChartInterval
}

export function resolveUsageChartFiltersFromPrefs(
  prefs: UserPrefs | null | undefined,
): UsageChartFilters {
  const serialized = parseUsageChartDateRangeFromPrefs(prefs)
  const dateRange = serialized
    ? parseUsageChartDateRange(serialized)
    : getStableUsageChartDateRange()

  const intervalFromPrefs = parseUsageChartIntervalFromPrefs(prefs)
  const chartInterval = resolveUsageChartIntervalForRange(
    intervalFromPrefs ?? DEFAULT_USAGE_CHART_INTERVAL,
    dateRange,
  )

  return { dateRange, chartInterval }
}

export function serializeUsageChartFilters(filters: UsageChartFilters) {
  return {
    serializedDateRange: serializeUsageChartDateRange(filters.dateRange),
    chartInterval: filters.chartInterval,
  }
}
