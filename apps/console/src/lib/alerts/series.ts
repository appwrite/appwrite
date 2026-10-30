/**
 * History of the value a monitor compares, rebuilt from project usage the
 * same way the server measures it: a counter summed over the monitor's window at
 * every bucket, a gauge at its latest sample, and growth as how far a gauge
 * rose over the window.
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

/** One usage request: the names it reads and the rows it keeps. */
type Series = {
  metrics: string[]
  queries: string[]
}

/**
 * How the value is read: summed `counter` events, the latest `gauge` sample,
 * or a gauge's `growth` across the window.
 */
type Kind = 'counter' | 'gauge' | 'growth'

type Source = {
  series: Series[]
  kind: Kind
}

/** Where each metric's writer records it for a scope, as `app/config/alerts.php` declares. */
function source(
  metric: AlertMetric,
  resourceType: AlertResourceType,
  resourceId: string,
): Source | null {
  const tagged = (type: string): string[] => [
    Query.equal('resourceType', type),
    ...(resourceType === 'project'
      ? []
      : [Query.equal('resourceId', resourceId)]),
  ]
  const whole = (kind: Kind): Source => ({
    series: [{ metrics: [metric], queries: [] }],
    kind,
  })
  switch (metric) {
    case 'network.requests':
    case 'network.inbound':
    case 'network.outbound':
    case 'auth.method.phone':
    case 'messages.sent':
    case 'messages.sms.sent':
    case 'messages.failed':
    case 'webhooks.events.sent':
    case 'webhooks.events.failed':
    case 'avatars.screenshotsGenerated':
      return whole('counter')
    case 'executions':
    case 'executions.mbSeconds':
      return resourceType === 'project'
        ? whole('counter')
        : {
            series: [{ metrics: [metric], queries: tagged(resourceType) }],
            kind: 'counter',
          }
    case 'realtime.connections':
    case 'users.mau':
      return whole('gauge')
    case 'users':
      return whole('growth')
    case 'databases.operations.reads':
    case 'databases.operations.writes':
      return {
        series: [
          {
            metrics: engines(metric),
            queries: resourceType === 'project' ? [] : tagged('database'),
          },
        ],
        kind: 'counter',
      }
    case 'databases.storage':
      return {
        series: [
          {
            metrics: engines(metric),
            queries: tagged(
              resourceType === 'project' ? 'project' : 'database',
            ),
          },
        ],
        kind: 'gauge',
      }
    case 'files.storage':
      return {
        series: [{ metrics: [metric], queries: tagged('project') }],
        kind: 'gauge',
      }
    case 'storage':
      return resourceType === 'project'
        ? {
            series: [
              {
                metrics: ['files.storage', ...engines('databases.storage')],
                queries: [Query.equal('resourceType', 'project')],
              },
              {
                metrics: ['deployments.storage'],
                queries: [Query.equal('resourceType', 'deployment')],
              },
              {
                metrics: ['builds.storage'],
                queries: [Query.equal('resourceType', 'build')],
              },
            ],
            kind: 'gauge',
          }
        : {
            series: [{ metrics: [metric], queries: tagged(resourceType) }],
            kind: 'gauge',
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
  // Growth compares a gauge across the window, so it reads like a counter's plan.
  const windowed = from.kind !== 'gauge'
  const plan = seriesPlan(rule.window, windowed, range)
  const end = now
  const start = end - plan.range * 1000
  // A counter's first window needs the buckets before the range too, and growth one more to rise from.
  const lead =
    from.kind === 'counter'
      ? plan.span - 1
      : from.kind === 'growth'
        ? plan.span
        : 0
  const fetchStart = start - lead * plan.bucket * 1000

  const usage = sdk.forProject(projectId).usage
  const responses: (Models.UsageEventList | Models.UsageGaugeList)[] =
    await Promise.all(
      from.series.map((series) => {
        const request = {
          metrics: series.metrics,
          queries: series.queries,
          interval: plan.interval,
          startAt: new Date(fetchStart).toISOString(),
          endAt: new Date(end).toISOString(),
          orderBy: 'time' as const,
          orderDir: 'asc' as const,
          limit: USAGE_API_MAX_LIMIT,
        }
        return from.kind === 'counter'
          ? usage.listEvents(request)
          : usage.listGauges(request)
      }),
    )

  const samples = responses.flatMap((response) =>
    (response.metrics ?? []).flatMap((entry) =>
      (entry.points ?? [])
        .filter(
          (point): point is typeof point & { time: string } => !!point.time,
        )
        .map((point) => ({ time: parseTime(point.time), value: point.value })),
    ),
  )
  const counter = from.kind === 'counter'
  const buckets = fillBuckets(samples, fetchStart, end, plan.bucket, counter)
  if (from.kind === 'gauge') return buckets
  return counter ? rolling(buckets, plan.span) : growth(buckets, plan.span)
}

/** Each point replaced by how far it rose over the `span` buckets before it; the first `span` are dropped. */
export function growth(points: AlertPoint[], span: number): AlertPoint[] {
  return points.slice(span).map((point, index) => ({
    time: point.time,
    value: point.value - points[index].value,
  }))
}
