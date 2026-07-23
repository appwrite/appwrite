import { addDays, addHours, addMinutes, endOfDay } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'

export type UsageChartBrushPoint = {
  day: Date
}

function getIntervalEndExclusive(
  start: Date,
  interval: UsageChartInterval,
): Date {
  if (interval === '15m') return addMinutes(start, 15)
  if (interval === '1h') return addHours(start, 1)
  return addDays(start, 1)
}

/**
 * Convert an inclusive brush selection on index-based usage charts into a
 * date range covering the selected interval buckets.
 */
export function resolveUsageChartBrushDateRange(
  points: readonly UsageChartBrushPoint[],
  leftIndex: number,
  rightIndex: number,
  interval: UsageChartInterval,
): DateRange | null {
  if (points.length === 0) return null

  const startIndex = Math.min(leftIndex, rightIndex)
  const endIndex = Math.max(leftIndex, rightIndex)
  if (
    !Number.isInteger(startIndex) ||
    !Number.isInteger(endIndex) ||
    startIndex < 0 ||
    endIndex >= points.length ||
    startIndex === endIndex
  ) {
    return null
  }

  const from = points[startIndex]?.day
  const lastBucketStart = points[endIndex]?.day
  if (!from || !lastBucketStart) return null

  const to =
    interval === '1d'
      ? endOfDay(lastBucketStart)
      : new Date(
          getIntervalEndExclusive(lastBucketStart, interval).getTime() - 1,
        )

  return { from, to }
}
