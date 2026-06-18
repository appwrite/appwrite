import { differenceInCalendarDays } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { resolveUsageDateBounds } from '@/lib/usage/usage-date-range'

export type UsageChartInterval = '1h' | '1d'

export const DEFAULT_USAGE_CHART_INTERVAL: UsageChartInterval = '1h'

export const USAGE_CHART_INTERVAL_OPTIONS: {
  value: UsageChartInterval
  label: string
}[] = [
  { value: '1h', label: '1h' },
  { value: '1d', label: '1d' },
]

function resolveChartIntervalDateBounds(dateRange: DateRange | undefined): {
  from: Date
  to: Date
} {
  return resolveUsageDateBounds(dateRange)
}

/** Max inclusive calendar days allowed for each interval (null = unlimited). */
const INTERVAL_MAX_RANGE_DAYS: Record<UsageChartInterval, number | null> = {
  '1h': 31,
  '1d': null,
}

export function getUsageChartIntervalMaxRangeDays(
  interval: UsageChartInterval,
): number | null {
  return INTERVAL_MAX_RANGE_DAYS[interval]
}

export function isUsageChartIntervalValidForRange(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
): boolean {
  const maxDays = getUsageChartIntervalMaxRangeDays(interval)
  if (maxDays === null) return true

  const { from, to } = resolveChartIntervalDateBounds(dateRange)
  const rangeDays = Math.max(1, differenceInCalendarDays(to, from) + 1)
  return rangeDays <= maxDays
}

export function getUsageChartIntervalDisabledReason(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
): string | undefined {
  if (isUsageChartIntervalValidForRange(interval, dateRange)) {
    return undefined
  }

  const maxDays = getUsageChartIntervalMaxRangeDays(interval)
  if (maxDays !== null) {
    return `Use a date range of ${maxDays} days or less for this interval.`
  }
  return undefined
}

/** Pick the finest interval still valid for the current range (fallback when range widens). */
export function resolveUsageChartIntervalForRange(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
): UsageChartInterval {
  if (isUsageChartIntervalValidForRange(interval, dateRange)) {
    return interval
  }
  if (isUsageChartIntervalValidForRange('1h', dateRange)) {
    return '1h'
  }
  return '1d'
}
