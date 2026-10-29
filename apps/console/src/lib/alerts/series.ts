/**
 * History of the value a monitor compares, rebuilt from project usage the
 * same way the server measures it: a counter summed over the monitor's window at
 * every bucket, a gauge at its latest sample.
 */

import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type {
  AlertComparator,
  AlertMetric,
  AlertResourceType,
} from '@/lib/appwrite/monitors'
import { USAGE_API_MAX_LIMIT } from '@/lib/usage/breakdown-limits'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { METRICS } from './metrics'

export type AlertPoint = { time: number; value: number }

export type AlertSeriesPlan = {
  interval: UsageChartInterval
  /** Bucket length in seconds. */
  bucket: number
  /** Buckets one window spans. */
  span: number
  /** Seconds of history shown. */
  range: number
}

const MINUTE = 60
const HOUR = 3600
const DAY = 86400

const INTERVALS: Record<number, UsageChartInterval> = {
  [MINUTE]: '1m',
  [15 * MINUTE]: '15m',
  [HOUR]: '1h',
  [DAY]: '1d',
}

/** Ranges a monitor's chart can show, in seconds. */
export const SERIES_RANGES = [12 * HOUR, DAY, 7 * DAY, 30 * DAY] as const

/**
 * Bucket size and history length a monitor's chart reads. `range` replaces the
 * default history length; pick one of `seriesRanges()` so it fits a page.
 */
export function seriesPlan(
  window: number,
  counter: boolean,
  range?: number,
): AlertSeriesPlan {
  if (!counter) {
    return { interval: '1h', bucket: HOUR, span: 1, range: range ?? 7 * DAY }
  }
  const bucket =
    window >= 7 * DAY
      ? DAY
      : window >= 6 * HOUR
        ? HOUR
        : window % (15 * MINUTE) === 0
          ? 15 * MINUTE
          : MINUTE
  const defaultRange =
    bucket === MINUTE
      ? 12 * HOUR
      : bucket === 15 * MINUTE
        ? DAY
        : bucket === HOUR
          ? 7 * DAY
          : 30 * DAY
  return {
    interval: INTERVALS[bucket],
    bucket,
    span: Math.max(1, Math.ceil(window / bucket)),
    range: range ?? defaultRange,
  }
}

/** The ranges whose buckets fit in one usage page for this monitor. */
export function seriesRanges(window: number, counter: boolean): number[] {
  const { bucket, span } = seriesPlan(window, counter)
  return SERIES_RANGES.filter(
    (range) => range / bucket + span <= USAGE_API_MAX_LIMIT,
  )
}

/** Each point replaced by the sum of the `span` points ending at it; the first `span - 1` are dropped. */
export function rolling(points: AlertPoint[], span: number): AlertPoint[] {
  const result: AlertPoint[] = []
  let sum = 0
  points.forEach((point, index) => {
    sum += point.value
    if (index >= span) sum -= points[index - span].value
    if (index >= span - 1) result.push({ time: point.time, value: sum })
  })
  return result
}

export function breaches(
  comparator: AlertComparator,
  value: number,
  threshold: number,
): boolean {
  switch (comparator) {
    case 'greaterThan':
      return value > threshold
    case 'greaterThanEqual':
      return value >= threshold
    case 'lessThan':
      return value < threshold
    case 'lessThanEqual':
      return value <= threshold
  }
}

/** Times the series starts breaching, i.e. when a monitor would have fired. */
export function firings(
  points: AlertPoint[],
  comparator: AlertComparator,
  threshold: number,
): number[] {
  const times: number[] = []
  let breaching = false
  for (const point of points) {
    const now = breaches(comparator, point.value, threshold)
    if (now && !breaching) times.push(point.time)
    breaching = now
  }
  return times
}

/**
 * One point per bucket from `start` to `end`. Missing counter buckets are
 * zero; a missing gauge bucket repeats the previous sample, since storage is
 * swept periodically rather than every bucket.
 */
export function fillBuckets(
  samples: { time: number; value: number }[],
  start: number,
  end: number,
  bucket: number,
  counter: boolean,
): AlertPoint[] {
  const size = bucket * 1000
  const values = new Map<number, number>()
  for (const sample of samples) {
    const key = Math.floor(sample.time / size) * size
    values.set(key, (values.get(key) ?? 0) + sample.value)
  }
  const points: AlertPoint[] = []
  let previous = 0
  for (let time = Math.floor(start / size) * size; time <= end; time += size) {
    const value = values.get(time) ?? (counter ? 0 : previous)
    previous = value
    points.push({ time, value })
  }
  return points
}

/** Usage names a database metric is recorded under, one per engine. */
function engines(metric: string): string[] {
  return [metric, `documentsdb.${metric}`, `vectorsdb.${metric}`]
}

type Source = {
  metrics: string[]
  queries: string[]
  counter: boolean
}

function source(
  metric: AlertMetric,
  resourceType: AlertResourceType,
  resourceId: string,
): Source | null {
  const scoped =
    resourceType === 'project' ? [] : [Query.equal('resourceId', resourceId)]
  switch (metric) {
    case 'bandwidth.inbound':
    case 'bandwidth.outbound':
      return {
        metrics: [
          metric === 'bandwidth.inbound'
            ? 'network.inbound'
            : 'network.outbound',
        ],
        queries: [Query.notEqual('resourceType', 'site')],
        counter: true,
      }
    case 'databases.reads':
    case 'databases.writes':
      return {
        metrics: engines(
          metric === 'databases.reads'
            ? 'databases.operations.reads'
            : 'databases.operations.writes',
        ),
        queries: scoped,
        counter: true,
      }
    case 'databases.storage':
      return {
        metrics: engines('databases.storage'),
        queries: [
          Query.equal(
            'resourceType',
            resourceType === 'project' ? 'project' : 'database',
          ),
          ...scoped,
        ],
        counter: false,
      }
    case 'files.storage':
      return resourceType === 'bucket'
        ? {
            metrics: ['storage'],
            queries: [Query.equal('resourceType', 'bucket'), ...scoped],
            counter: false,
          }
        : {
            metrics: ['files.storage'],
            queries: [Query.equal('resourceType', 'project')],
            counter: false,
          }
    default:
      return null
  }
}

/** Whether the console can chart a metric; dedicated database gauges live elsewhere. */
export function hasSeries(metric: AlertMetric): boolean {
  return source(metric, 'project', '') !== null
}

function parseTime(time: string): number {
  return Date.parse(
    /[zZ]|[+-]\d\d:?\d\d$/.test(time) ? time : `${time.replace(' ', 'T')}Z`,
  )
}

/**
 * The value the monitor compared at each bucket over the plan's range, or null
 * when the metric has no usage series the console reads.
 */
export async function fetchAlertSeries(
  projectId: string,
  rule: {
    metric: AlertMetric
    resourceType: AlertResourceType
    resourceId: string
    window: number
  },
  now: number = Date.now(),
  range?: number,
): Promise<AlertPoint[] | null> {
  const from = source(rule.metric, rule.resourceType, rule.resourceId)
  if (!from) return null
  const counter = METRICS[rule.metric].counter
  const plan = seriesPlan(rule.window, counter, range)
  const end = now
  const start = end - plan.range * 1000
  // A counter's first window needs the buckets before the range too.
  const fetchStart = start - (plan.span - 1) * plan.bucket * 1000

  const request = {
    metrics: from.metrics,
    queries: from.queries,
    interval: plan.interval,
    startAt: new Date(fetchStart).toISOString(),
    endAt: new Date(end).toISOString(),
    orderBy: 'time' as const,
    orderDir: 'asc' as const,
    limit: USAGE_API_MAX_LIMIT,
  }
  const usage = sdk.forProject(projectId).usage
  const response: Models.UsageEventList | Models.UsageGaugeList = counter
    ? await usage.listEvents(request)
    : await usage.listGauges(request)

  const samples = (response.metrics ?? []).flatMap((entry) =>
    (entry.points ?? [])
      .filter((point): point is typeof point & { time: string } => !!point.time)
      .map((point) => ({ time: parseTime(point.time), value: point.value })),
  )
  const buckets = fillBuckets(samples, fetchStart, end, plan.bucket, counter)
  return counter ? rolling(buckets, plan.span) : buckets
}
