import { differenceInCalendarDays, differenceInHours } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { resolveUsageDateBounds } from '@/lib/usage/usage-date-range'

export type UsageChartInterval = '1m' | '1h' | '1d'

export const DEFAULT_USAGE_CHART_INTERVAL: UsageChartInterval = '1h'

export const USAGE_CHART_INTERVAL_OPTIONS: {
  value: UsageChartInterval
  label: string
}[] = [
  { value: '1m', label: '1m' },
  { value: '1h', label: '1h' },
  { value: '1d', label: '1d' },
]

/** Finest to coarsest — used when coarsening interval for wider date ranges. */
export const USAGE_CHART_INTERVAL_COARSEN_ORDER: UsageChartInterval[] = [
  '1m',
  '1h',
  '1d',
]

function resolveChartIntervalDateBounds(dateRange: DateRange | undefined): {
  from: Date
  to: Date
} {
  return resolveUsageDateBounds(dateRange)
}

/** Max inclusive calendar days allowed for each interval (null = unlimited). */
const INTERVAL_MAX_RANGE_DAYS: Record<UsageChartInterval, number | null> = {
  '1m': null,
  '1h': 31,
  '1d': null,
}

/** Max duration in hours (checked when calendar-day limit is null). */
const INTERVAL_MAX_RANGE_HOURS: Partial<Record<UsageChartInterval, number>> = {
  '1m': 24,
}

export function getUsageChartIntervalMaxRangeDays(
  interval: UsageChartInterval,
): number | null {
  return INTERVAL_MAX_RANGE_DAYS[interval]
}

export function getUsageChartIntervalMaxRangeHours(
  interval: UsageChartInterval,
): number | null {
  return INTERVAL_MAX_RANGE_HOURS[interval] ?? null
}

export function isUsageChartIntervalValidForRange(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
): boolean {
  const { from, to } = resolveChartIntervalDateBounds(dateRange)

  const maxHours = getUsageChartIntervalMaxRangeHours(interval)
  if (maxHours !== null) {
    return differenceInHours(to, from) <= maxHours
  }

  const maxDays = getUsageChartIntervalMaxRangeDays(interval)
  if (maxDays === null) return true

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

  const maxHours = getUsageChartIntervalMaxRangeHours(interval)
  if (maxHours !== null) {
    return `Use a date range of ${maxHours} hours or less for this interval.`
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

  const startIndex = USAGE_CHART_INTERVAL_COARSEN_ORDER.indexOf(interval)
  const candidates =
    startIndex >= 0
      ? USAGE_CHART_INTERVAL_COARSEN_ORDER.slice(startIndex + 1)
      : USAGE_CHART_INTERVAL_COARSEN_ORDER.slice(1)

  for (const candidate of candidates) {
    if (isUsageChartIntervalValidForRange(candidate, dateRange)) {
      return candidate
    }
  }

  return '1d'
}
