import {
  addDays,
  addHours,
  isSameDay,
  parseISO,
  startOfDay,
  startOfHour,
} from 'date-fns'
import type { Models } from '@appwrite.io/console'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import type {
  AnalyticsChartInterval,
  AnalyticsRange,
} from '@/lib/react-query/hooks'

export type AnalyticsSeriesKey = 'events' | 'visitors' | 'sessions'

export type AnalyticsChartPoint = {
  /** Short axis label. */
  date: string
  /** Bucket start in local time (used by the shared axis and brush). */
  day: Date
  /** Long label for the tooltip. */
  fullDate: string
  events: number
  visitors: number
  sessions: number
}

/**
 * Map an API bucket timestamp onto the local bucket grid.
 *
 * Daily buckets are keyed by their calendar date part so a `YYYY-MM-DDT00:00Z`
 * bucket never shifts a day for viewers west of UTC. Hourly buckets are real
 * instants and are floored to the local hour.
 */
function bucketKey(
  value: string | null | undefined,
  interval: AnalyticsChartInterval,
): number | null {
  if (!value) return null
  if (interval === '1d') {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
    if (!match) return null
    const [, year, month, day] = match
    const date = new Date(Number(year), Number(month) - 1, Number(day))
    return Number.isNaN(date.getTime()) ? null : date.getTime()
  }
  const parsed = parseISO(value)
  return Number.isNaN(parsed.getTime()) ? null : startOfHour(parsed).getTime()
}

function bucketStart(date: Date, interval: AnalyticsChartInterval): Date {
  return interval === '1d' ? startOfDay(date) : startOfHour(date)
}

function nextBucket(date: Date, interval: AnalyticsChartInterval): Date {
  return interval === '1d' ? addDays(date, 1) : addHours(date, 1)
}

/** Safety cap so a malformed range can never build an unbounded grid. */
const MAX_BUCKETS = 2000

/**
 * Zero-filled series spanning the whole range, one point per bucket, so the
 * chart always covers the selected window even when the API omits empty
 * buckets.
 */
export function buildAnalyticsChartPoints(
  metrics: readonly Models.AnalyticsMetric[],
  range: AnalyticsRange,
  interval: AnalyticsChartInterval,
): AnalyticsChartPoint[] {
  const from = new Date(range.startAt)
  const to = new Date(range.endAt)
  if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) return []

  const lookup = new Map<
    number,
    { events: number; visitors: number; sessions: number }
  >()
  for (const metric of metrics) {
    const key = bucketKey(metric.date, interval)
    if (key == null) continue
    const current = lookup.get(key) ?? { events: 0, visitors: 0, sessions: 0 }
    lookup.set(key, {
      events: current.events + (metric.events ?? 0),
      visitors: current.visitors + (metric.visitors ?? 0),
      sessions: current.sessions + (metric.sessions ?? 0),
    })
  }

  const spansMultipleDays = !isSameDay(from, to)
  const points: AnalyticsChartPoint[] = []
  let cursor = bucketStart(from, interval)
  const end = bucketStart(to, interval).getTime()

  while (cursor.getTime() <= end && points.length < MAX_BUCKETS) {
    const values = lookup.get(cursor.getTime())
    points.push({
      date:
        interval === '1d'
          ? formatLocalizedDate(cursor, 'd MMM')
          : spansMultipleDays
            ? formatLocalizedDate(cursor, 'd MMM HH:mm')
            : formatLocalizedDate(cursor, 'HH:mm'),
      day: cursor,
      fullDate:
        interval === '1d'
          ? formatLocalizedDate(cursor, 'EEE, MMM d, yyyy')
          : formatLocalizedDate(cursor, 'MMM d, yyyy HH:mm'),
      events: values?.events ?? 0,
      visitors: values?.visitors ?? 0,
      sessions: values?.sessions ?? 0,
    })
    cursor = nextBucket(cursor, interval)
  }

  return points
}

/** A time window to read the flat aggregate for, anchored at a chart bucket. */
export type AnalyticsBucketWindow = {
  /** Index of the first chart bucket the window covers. */
  index: number
  /** Number of consecutive buckets in the window. */
  size: number
  range: AnalyticsRange
}

/**
 * Most windows one chart will read the flat aggregate for. Each window is one
 * API request, so long ranges group consecutive buckets instead of fanning
 * out to hundreds of requests (e.g. hourly over a week = 168 buckets).
 */
export const MAX_AGGREGATE_WINDOWS = 60

/**
 * Split a chart's bucket grid into at most `maxWindows` windows of equal
 * bucket count, clamped to the selected range. Used to plot metrics that only
 * exist on the flat aggregate (bounce rate, durations, views per visit).
 */
export function buildBucketWindows(
  points: readonly AnalyticsChartPoint[],
  range: AnalyticsRange,
  maxWindows: number = MAX_AGGREGATE_WINDOWS,
): AnalyticsBucketWindow[] {
  if (points.length === 0) return []
  const rangeStart = new Date(range.startAt).getTime()
  const rangeEnd = new Date(range.endAt).getTime()
  const size = Math.max(1, Math.ceil(points.length / maxWindows))
  const windows: AnalyticsBucketWindow[] = []
  for (let index = 0; index < points.length; index += size) {
    const nextIndex = index + size
    const start = Math.max(rangeStart, points[index].day.getTime())
    const end =
      nextIndex < points.length
        ? Math.min(rangeEnd, points[nextIndex].day.getTime() - 1)
        : rangeEnd
    if (end < start) continue
    windows.push({
      index,
      size: Math.min(size, points.length - index),
      range: {
        startAt: new Date(start).toISOString(),
        endAt: new Date(end).toISOString(),
      },
    })
  }
  return windows
}

export function sumAnalyticsSeries(
  points: readonly AnalyticsChartPoint[],
): Record<AnalyticsSeriesKey, number> {
  return points.reduce(
    (acc, point) => ({
      events: acc.events + point.events,
      visitors: acc.visitors + point.visitors,
      sessions: acc.sessions + point.sessions,
    }),
    { events: 0, visitors: 0, sessions: 0 },
  )
}

/** Percent change, rounded to one decimal; `undefined` when not comparable. */
export function analyticsChangePercent(
  current: number | null | undefined,
  previous: number | null | undefined,
): number | undefined {
  if (current == null || previous == null) return undefined
  if (!Number.isFinite(current) || !Number.isFinite(previous)) return undefined
  if (previous <= 0) return current > 0 ? 100 : 0
  return Number((((current - previous) / previous) * 100).toFixed(1))
}
