import { format, isSameDay } from 'date-fns'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'

/** Target tick count for overview charts (narrow chart column beside breakdown). */
export const OVERVIEW_USAGE_CHART_X_AXIS_MAX_TICKS = 6

/** Target tick count for full-width usage page charts. */
export const USAGE_CHART_X_AXIS_MAX_TICKS = 7

type UsageChartXAxisVariant = 'overview' | 'full'

type UsageChartXAxisPoint = {
  date: string
  day: Date
}

function spansMultipleDays(rangeFrom: Date, rangeTo: Date): boolean {
  return !isSameDay(rangeFrom, rangeTo)
}

function spansMultipleYears(rangeFrom: Date, rangeTo: Date): boolean {
  return rangeFrom.getFullYear() !== rangeTo.getFullYear()
}

function isDayStart(day: Date, interval: UsageChartInterval): boolean {
  if (interval === '1d') return true
  if (interval === '1h') return day.getHours() === 0
  return day.getHours() === 0 && day.getMinutes() === 0
}

export function resolveUsageChartXAxisMaxTicks(
  interval: UsageChartInterval,
  variant: UsageChartXAxisVariant = 'full',
): number {
  const base =
    variant === 'overview'
      ? OVERVIEW_USAGE_CHART_X_AXIS_MAX_TICKS
      : USAGE_CHART_X_AXIS_MAX_TICKS

  switch (interval) {
    case '15m':
      return Math.min(base, 6)
    case '1d':
      return Math.min(base + 2, 8)
    case '1h':
    default:
      return base
  }
}

/**
 * Evenly spaced data indices for x-axis ticks - always includes first and last point.
 */
export function resolveChartXAxisTickIndices(
  pointCount: number,
  maxTicks: number,
): number[] {
  if (pointCount <= 0) return []

  if (pointCount <= maxTicks) {
    return Array.from({ length: pointCount }, (_, index) => index)
  }

  const indices: number[] = [0]
  const step = (pointCount - 1) / (maxTicks - 1)

  for (let tick = 1; tick < maxTicks - 1; tick += 1) {
    indices.push(Math.round(tick * step))
  }

  indices.push(pointCount - 1)

  return [...new Set(indices)].sort((a, b) => a - b)
}

export function resolveUsageChartXAxisTickIndices(
  pointCount: number,
  interval: UsageChartInterval,
  variant: UsageChartXAxisVariant = 'full',
): number[] {
  return resolveChartXAxisTickIndices(
    pointCount,
    resolveUsageChartXAxisMaxTicks(interval, variant),
  )
}

export function createSeriesChartXAxisTickFormatter(
  pointCount: number,
  maxTicks: number = USAGE_CHART_X_AXIS_MAX_TICKS,
  formatLabel?: (value: string, index: number) => string,
  /** Labels by data index when the X axis is index-based (no category dataKey). */
  labelsByIndex?: readonly string[],
): (value: string, index: number) => string {
  const tickIndexSet = new Set(
    resolveChartXAxisTickIndices(pointCount, maxTicks),
  )

  return (value: string, index: number) => {
    if (!tickIndexSet.has(index)) return ''
    const label = labelsByIndex?.[index] ?? value
    return formatLabel ? formatLabel(label, index) : label
  }
}

export function resolveUsageChartXAxisTickValues<
  T extends { date: string },
>(
  points: readonly T[],
  interval: UsageChartInterval,
  variant: UsageChartXAxisVariant = 'full',
): string[] {
  return resolveUsageChartXAxisTickIndices(points.length, interval, variant)
    .map((index) => points[index]?.date)
    .filter((value): value is string => Boolean(value))
}

/**
 * Compact x-axis labels - tooltips keep the full `date` string from chart points.
 */
export function formatUsageChartXAxisLabel(
  day: Date,
  interval: UsageChartInterval,
  rangeFrom: Date,
  rangeTo: Date,
  previousTickDay?: Date,
): string {
  const multiDay = spansMultipleDays(rangeFrom, rangeTo)
  const isNewDay =
    previousTickDay != null && !isSameDay(day, previousTickDay)

  switch (interval) {
    case '15m':
      if (!multiDay) {
        return format(day, 'HH:mm')
      }
      if (isDayStart(day, interval) || isNewDay) {
        return formatLocalizedDate(day, 'd MMM')
      }
      return format(day, 'HH:mm')

    case '1h':
      if (!multiDay) {
        return format(day, 'HH:mm')
      }
      if (isDayStart(day, interval) || isNewDay) {
        return formatLocalizedDate(day, 'd MMM')
      }
      return format(day, 'HH:mm')

    case '1d':
      if (spansMultipleYears(rangeFrom, rangeTo)) {
        return formatLocalizedDate(day, 'd MMM yy')
      }
      return formatLocalizedDate(day, 'd MMM')

    default:
      return formatLocalizedDate(day, 'd MMM')
  }
}

export function createUsageChartXAxisTickFormatter(
  points: readonly UsageChartXAxisPoint[],
  tickIndices: readonly number[],
  interval: UsageChartInterval,
  rangeFrom: Date,
  rangeTo: Date,
): (value: string, index: number) => string {
  const tickIndexSet = new Set(tickIndices)
  const sortedTickIndices = [...tickIndices].sort((a, b) => a - b)

  return (_value: string, index: number) => {
    if (!tickIndexSet.has(index)) return ''

    const point = points[index]
    if (!point?.day) return ''

    const tickPosition = sortedTickIndices.indexOf(index)
    const previousTickDay =
      tickPosition > 0
        ? points[sortedTickIndices[tickPosition - 1]!]?.day
        : undefined

    return formatUsageChartXAxisLabel(
      point.day,
      interval,
      rangeFrom,
      rangeTo,
      previousTickDay,
    )
  }
}
