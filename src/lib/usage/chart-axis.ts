import { format, isSameDay } from 'date-fns'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'

/** Target tick count for overview charts (narrow chart column beside breakdown). */
export const OVERVIEW_USAGE_CHART_X_AXIS_MAX_TICKS = 5

/** Target tick count for full-width usage page charts. */
export const USAGE_CHART_X_AXIS_MAX_TICKS = 7

/**
 * Recharts XAxis `interval` — show at most ~maxTicks labels across the series.
 */
export function resolveUsageChartXAxisInterval(
  pointCount: number,
  maxTicks = USAGE_CHART_X_AXIS_MAX_TICKS,
): number | 'preserveStartEnd' {
  if (pointCount <= 1 || pointCount <= maxTicks) {
    return 'preserveStartEnd'
  }

  return Math.max(0, Math.ceil(pointCount / maxTicks) - 1)
}

/**
 * Compact x-axis labels — tooltips keep the full `date` string from chart points.
 */
export function formatUsageChartXAxisLabel(
  day: Date,
  interval: UsageChartInterval,
  rangeFrom: Date,
  rangeTo: Date,
): string {
  if (interval === '15m' || interval === '1h') {
    const spansMultipleDays = !isSameDay(rangeFrom, rangeTo)
    if (spansMultipleDays) {
      return format(day, 'd MMM')
    }
    return format(day, 'HH:mm')
  }

  return format(day, 'd MMM')
}
