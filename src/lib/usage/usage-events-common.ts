import {
  addDays,
  addHours,
  differenceInCalendarDays,
  differenceInHours,
  endOfDay,
  format,
  parseISO,
  startOfDay,
  startOfHour,
  subDays,
} from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

/** API max per listEvents request. */
export const USAGE_EVENTS_MAX_LIMIT = 500

/** Single-page fetch for top-endpoint aggregation (no pagination). */
export const TOP_ENDPOINTS_QUERY_LIMIT = USAGE_EVENTS_MAX_LIMIT

export interface UsageChartPoint {
  date: string
  day: Date
  total: number
}

export interface UsageTopEndpoint {
  id: string
  method: string
  statusCode: number
  path: string
  count: number
}

export interface ProjectUsageChartOverview {
  total: number
  changePercent: number
  chartPoints: UsageChartPoint[]
}

export interface ProjectUsageTopEndpointsOverview {
  topEndpoints: UsageTopEndpoint[]
}

export interface OverviewUsagePeriod {
  from: Date
  to: Date
  previousFrom: Date
  previousTo: Date
  interval: string
}

export function groupByIntervalQuery(interval: string): string {
  return new Query('groupByInterval', 'time', [interval]).toString()
}

export function resolveDateBounds(dateRange: DateRange | undefined): {
  from: Date
  to: Date
} {
  const to = dateRange?.to
    ? endOfDay(dateRange.to)
    : endOfDay(new Date())
  const from = dateRange?.from
    ? startOfDay(dateRange.from)
    : startOfDay(subDays(to, 29))

  return { from, to }
}

export function resolveOverviewUsagePeriod(
  dateRange: DateRange | undefined,
): OverviewUsagePeriod {
  const { from, to } = resolveDateBounds(dateRange)
  const interval = getGroupInterval(from, to)
  const rangeDays = Math.max(1, differenceInCalendarDays(to, from) + 1)
  const previousTo = endOfDay(subDays(from, 1))
  const previousFrom = startOfDay(subDays(previousTo, rangeDays - 1))

  return { from, to, previousFrom, previousTo, interval }
}

export function getGroupInterval(from: Date, to: Date): string {
  const days = Math.max(1, differenceInCalendarDays(to, from) + 1)
  if (days <= 2) return '1h'
  return '1d'
}

/** Bucket count for groupByInterval queries; capped at API max. */
export function getChartQueryLimit(
  from: Date,
  to: Date,
  interval: string,
): number {
  if (interval === '1h') {
    const hours = Math.max(1, differenceInHours(to, from) + 1)
    return Math.min(hours, USAGE_EVENTS_MAX_LIMIT)
  }

  const days = Math.max(1, differenceInCalendarDays(to, from) + 1)
  return Math.min(days, USAGE_EVENTS_MAX_LIMIT)
}

function mergeValuesByTime(events: Models.UsageEvent[]): Map<string, number> {
  const merged = new Map<string, number>()
  for (const event of events) {
    merged.set(event.time, (merged.get(event.time) ?? 0) + event.value)
  }
  return merged
}

function sumMergedValues(merged: Map<string, number>): number {
  return Array.from(merged.values()).reduce((sum, value) => sum + value, 0)
}

function normalizeBucketTime(date: Date, interval: string): Date {
  return interval === '1h' ? startOfHour(date) : startOfDay(date)
}

function formatChartPointLabel(day: Date, interval: string): string {
  if (interval === '1h') {
    return format(day, 'HH:mm')
  }
  return format(day, 'd MMM')
}

function buildBucketLookup(
  merged: Map<string, number>,
  interval: string,
): Map<number, number> {
  const lookup = new Map<number, number>()
  for (const [time, value] of merged.entries()) {
    const bucket = normalizeBucketTime(parseISO(time), interval)
    const key = bucket.getTime()
    lookup.set(key, (lookup.get(key) ?? 0) + value)
  }
  return lookup
}

/** Fill missing interval buckets with zero so the chart spans the full date range. */
export function fillChartPointsGaps(
  merged: Map<string, number>,
  from: Date,
  to: Date,
  interval: string,
): UsageChartPoint[] {
  const lookup = buildBucketLookup(merged, interval)
  const points: UsageChartPoint[] = []
  let cursor =
    interval === '1h' ? startOfHour(from) : startOfDay(from)
  const endCursor =
    interval === '1h' ? startOfHour(to) : startOfDay(to)

  while (cursor.getTime() <= endCursor.getTime()) {
    const key = cursor.getTime()
    points.push({
      date: formatChartPointLabel(cursor, interval),
      day: cursor,
      total: lookup.get(key) ?? 0,
    })
    cursor = interval === '1h' ? addHours(cursor, 1) : addDays(cursor, 1)
  }

  return points
}

function splitEventsByPeriod(
  events: Models.UsageEvent[],
  currentFrom: Date,
): {
  current: Models.UsageEvent[]
  previous: Models.UsageEvent[]
} {
  const currentFromMs = startOfDay(currentFrom).getTime()
  const current: Models.UsageEvent[] = []
  const previous: Models.UsageEvent[] = []

  for (const event of events) {
    const eventMs = parseISO(event.time).getTime()
    if (eventMs >= currentFromMs) {
      current.push(event)
    } else {
      previous.push(event)
    }
  }

  return { current, previous }
}

function aggregateTopEndpoints(
  events: Models.UsageEvent[],
  limit = 7,
): UsageTopEndpoint[] {
  const grouped = new Map<
    string,
    {
      method: string
      statusCode: number
      path: string
      count: number
    }
  >()

  for (const event of events) {
    const method = event.method || 'GET'
    const statusCode = Number.parseInt(event.status, 10) || 0
    const path = event.path || '/'
    const key = `${method}|${statusCode}|${path}`
    const existing = grouped.get(key)
    if (existing) {
      existing.count += event.value
      continue
    }
    grouped.set(key, { method, statusCode, path, count: event.value })
  }

  return Array.from(grouped.entries())
    .sort((a, b) => b[1].count - a[1].count)
    .slice(0, limit)
    .map(([key, item]) => ({
      id: key,
      method: item.method,
      statusCode: item.statusCode,
      path: item.path,
      count: item.count,
    }))
}

async function listUsageEvents(
  projectId: string,
  queries: string[],
): Promise<Models.UsageEvent[]> {
  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.usage.listEvents({
    queries,
    total: false,
  })
  return response.events ?? []
}

export function computeChangePercent(
  current: number,
  previous: number,
): number {
  if (previous <= 0) {
    return current > 0 ? 100 : 0
  }
  return Number((((current - previous) / previous) * 100).toFixed(1))
}

/**
 * One listEvents call: current + previous period buckets for chart, total, and change %.
 */
export async function fetchProjectUsageChartOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
): Promise<ProjectUsageChartOverview> {
  if (!projectId) {
    return {
      total: 0,
      changePercent: 0,
      chartPoints: [],
    }
  }

  const { from, to, previousFrom, interval } =
    resolveOverviewUsagePeriod(dateRange)
  const limit = getChartQueryLimit(previousFrom, to, interval)

  const events = await listUsageEvents(projectId, [
    Query.equal('metric', [...metrics]),
    Query.greaterThanEqual('time', previousFrom.toISOString()),
    Query.lessThanEqual('time', to.toISOString()),
    groupByIntervalQuery(interval),
    Query.orderAsc('time'),
    Query.limit(limit),
  ])

  const { current, previous } = splitEventsByPeriod(events, from)
  const currentMerged = mergeValuesByTime(current)
  const previousMerged = mergeValuesByTime(previous)
  const total = sumMergedValues(currentMerged)
  const previousTotal = sumMergedValues(previousMerged)

  return {
    total,
    changePercent: computeChangePercent(total, previousTotal),
    chartPoints: fillChartPointsGaps(currentMerged, from, to, interval),
  }
}

/**
 * One listEvents call: aggregate top path/method/status rows for the current range.
 */
export async function fetchProjectUsageTopEndpoints(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
): Promise<ProjectUsageTopEndpointsOverview> {
  if (!projectId) {
    return { topEndpoints: [] }
  }

  const { from, to } = resolveOverviewUsagePeriod(dateRange)

  const events = await listUsageEvents(projectId, [
    Query.equal('metric', [...metrics]),
    Query.greaterThanEqual('time', from.toISOString()),
    Query.lessThanEqual('time', to.toISOString()),
    Query.orderDesc('time'),
    Query.limit(TOP_ENDPOINTS_QUERY_LIMIT),
  ])

  return {
    topEndpoints: aggregateTopEndpoints(events),
  }
}
