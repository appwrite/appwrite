import {
  addDays,
  addHours,
  differenceInCalendarDays,
  endOfDay,
  format,
  isSameDay,
  parseISO,
  startOfDay,
  startOfHour,
  subDays,
} from 'date-fns'
import type { DateRange } from 'react-day-picker'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import {
  isFullCalendarDayRange,
  resolveUsageDateBounds,
} from '@/lib/usage/usage-date-range'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import { areUsageBreakdownQueriesEnabled } from '@/lib/debug-overrides'

export type { UsageChartInterval } from '@/lib/usage/chart-interval'
export {
  DEFAULT_USAGE_CHART_INTERVAL,
  USAGE_CHART_INTERVAL_OPTIONS,
  getUsageChartIntervalDisabledReason,
  getUsageChartIntervalMaxRangeDays,
  isUsageChartIntervalValidForRange,
  resolveUsageChartIntervalForRange,
} from '@/lib/usage/chart-interval'

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
  changePercent: number
  chartPoints: UsageChartPoint[]
}

export interface ProjectUsageMetricOverview extends ProjectUsageChartOverview {
  topEndpoints: UsageTopEndpoint[]
}

export interface ProjectUsageTopEndpointsOverview {
  topEndpoints: UsageTopEndpoint[]
}

export interface OverviewUsagePeriod {
  from: Date
  to: Date
  previousFrom: Date
  previousTo: Date
  interval: UsageChartInterval
}

export function resolveDateBounds(dateRange: DateRange | undefined): {
  from: Date
  to: Date
} {
  return resolveUsageDateBounds(dateRange)
}

export function resolveOverviewUsagePeriod(
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): OverviewUsagePeriod {
  const { from, to } = resolveUsageDateBounds(dateRange)

  let previousFrom: Date
  let previousTo: Date

  if (isFullCalendarDayRange(from, to)) {
    const rangeDays = Math.max(1, differenceInCalendarDays(to, from) + 1)
    previousTo = endOfDay(subDays(from, 1))
    previousFrom = startOfDay(subDays(previousTo, rangeDays - 1))
  } else {
    const durationMs = to.getTime() - from.getTime()
    previousTo = new Date(from.getTime())
    previousFrom = new Date(from.getTime() - durationMs)
  }

  return { from, to, previousFrom, previousTo, interval }
}

function getIntervalStart(date: Date, interval: UsageChartInterval): Date {
  if (interval === '1h') return startOfHour(date)
  return startOfDay(date)
}

function advanceIntervalCursor(date: Date, interval: UsageChartInterval): Date {
  if (interval === '1h') return addHours(date, 1)
  return addDays(date, 1)
}

interface ListUsageEventGroupsParams {
  metric: string
  interval?: UsageChartInterval
  startAt: string
  endAt: string
  dimensions?: string[]
  resource?: string
  resourceId?: string
}

function mergeValuesByTime(groups: Models.UsageGroup[]): Map<string, number> {
  const merged = new Map<string, number>()
  for (const group of groups) {
    merged.set(group.time, (merged.get(group.time) ?? 0) + group.value)
  }
  return merged
}

function normalizeBucketTime(
  date: Date,
  interval: UsageChartInterval,
): Date {
  return getIntervalStart(date, interval)
}

function formatChartPointLabel(
  day: Date,
  interval: UsageChartInterval,
  rangeFrom: Date,
  rangeTo: Date,
): string {
  if (interval === '1h') {
    const spansMultipleDays = !isSameDay(rangeFrom, rangeTo)
    return spansMultipleDays ? format(day, 'd MMM HH:mm') : format(day, 'HH:mm')
  }
  return format(day, 'd MMM')
}

function buildBucketLookup(
  merged: Map<string, number>,
  interval: UsageChartInterval,
): Map<number, number> {
  const lookup = new Map<number, number>()
  for (const [time, value] of merged.entries()) {
    const bucket = normalizeBucketTime(parseISO(time), interval)
    const key = bucket.getTime()
    lookup.set(key, (lookup.get(key) ?? 0) + value)
  }
  return lookup
}

/** Sum all bucket values in a usage chart series. */
export function sumUsageChartPoints(points: UsageChartPoint[]): number {
  return points.reduce((sum, point) => sum + point.total, 0)
}

export function mergeChartPointsSeries(
  series: UsageChartPoint[][],
): UsageChartPoint[] {
  if (series.length === 0) return []
  const [first] = series
  if (!first) return []

  return first.map((point, index) => ({
    ...point,
    total: series.reduce((sum, items) => sum + (items[index]?.total ?? 0), 0),
  }))
}

export function mergeTopEndpoints(
  lists: UsageTopEndpoint[][],
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): UsageTopEndpoint[] {
  const grouped = new Map<string, UsageTopEndpoint>()

  for (const list of lists) {
    for (const item of list) {
      const existing = grouped.get(item.id)
      if (existing) {
        existing.count += item.count
        continue
      }
      grouped.set(item.id, { ...item })
    }
  }

  return Array.from(grouped.values())
    .sort((a, b) => b.count - a.count)
    .slice(0, limit)
}

function mergeUsageMetricSeries(
  results: UsageMetricSeriesResult[],
): ProjectUsageMetricOverview {
  if (results.length === 0) {
    return { changePercent: 0, chartPoints: [], topEndpoints: [] }
  }

  const chartPoints = mergeChartPointsSeries(results.map((result) => result.chartPoints))
  const previousChartPoints = mergeChartPointsSeries(
    results.map((result) => result.previousChartPoints),
  )

  return {
    chartPoints,
    topEndpoints: mergeTopEndpoints(results.map((result) => result.topEndpoints)),
    changePercent: computeChangePercent(
      sumUsageChartPoints(chartPoints),
      sumUsageChartPoints(previousChartPoints),
    ),
  }
}

interface UsageMetricSeriesResult {
  chartPoints: UsageChartPoint[]
  previousChartPoints: UsageChartPoint[]
  topEndpoints: UsageTopEndpoint[]
}

export type { UsageMetricSeriesResult }

const TOP_ENDPOINTS_DIMENSIONS = ['path', 'method', 'status'] as const

function mapBreakdownGroupsToEndpoints(
  groups: Models.UsageGroup[],
  dimensions: readonly string[],
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): UsageTopEndpoint[] {
  let items: UsageTopEndpoint[]

  if (dimensions.length === 1 && dimensions[0] === 'resourceId') {
    items = groups.map((group, index) => {
      const resourceId = group.resourceId?.trim() || ''
      return {
        id: resourceId || `resource-${index}`,
        method: '',
        statusCode: 0,
        path: resourceId,
        count: group.value,
      }
    })
  } else {
    items = groups.map((group, index) => {
      const method = group.method || 'GET'
      const statusCode = Number.parseInt(group.status ?? '', 10) || 0
      const path = group.path || '/'
      return {
        id: `${method}|${statusCode}|${path}|${index}`,
        method,
        statusCode,
        path,
        count: group.value,
      }
    })
  }

  return items.sort((a, b) => b.count - a.count).slice(0, limit)
}

async function fetchUsageMetricChartSeries(
  projectId: string,
  metric: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<Pick<UsageMetricSeriesResult, 'chartPoints' | 'previousChartPoints'>> {
  const {
    from,
    to,
    previousFrom,
    previousTo,
    interval: resolvedInterval,
  } = resolveOverviewUsagePeriod(dateRange, interval)

  const groups = await listUsageEventGroups(projectId, {
    metric,
    interval: resolvedInterval,
    startAt: previousFrom.toISOString(),
    endAt: to.toISOString(),
  })

  const { current, previous } = splitGroupsByPeriod(groups, from)
  const currentMerged = mergeValuesByTime(current)
  const previousMerged = mergeValuesByTime(previous)

  return {
    chartPoints: fillChartPointsGaps(currentMerged, from, to, resolvedInterval),
    previousChartPoints: fillChartPointsGaps(
      previousMerged,
      previousFrom,
      previousTo,
      resolvedInterval,
    ),
  }
}

async function fetchUsageMetricBreakdown(
  projectId: string,
  metric: string,
  dateRange: DateRange | undefined,
  dimensions: readonly string[],
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): Promise<UsageTopEndpoint[]> {
  const { from, to } = resolveOverviewUsagePeriod(dateRange)

  const groups = await listUsageEventGroups(projectId, {
    metric,
    dimensions: [...dimensions],
    startAt: from.toISOString(),
    endAt: to.toISOString(),
  })

  return mapBreakdownGroupsToEndpoints(groups, dimensions, breakdownLimit)
}

async function fetchUsageMetricSeries(
  projectId: string,
  metric: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  dimensions: readonly string[] = TOP_ENDPOINTS_DIMENSIONS,
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): Promise<UsageMetricSeriesResult> {
  const breakdownEnabled = areUsageBreakdownQueriesEnabled()
  const [chartSeries, topEndpoints] = await Promise.all([
    fetchUsageMetricChartSeries(projectId, metric, dateRange, interval),
    dimensions.length > 0 && breakdownEnabled
      ? fetchUsageMetricBreakdown(
          projectId,
          metric,
          dateRange,
          dimensions,
          breakdownLimit,
        )
      : Promise.resolve([]),
  ])

  return {
    ...chartSeries,
    topEndpoints,
  }
}

/**
 * Chart series (with interval) plus flat top-N breakdown (no interval).
 */
export async function fetchProjectUsageMetricsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  dimensions: readonly string[] = TOP_ENDPOINTS_DIMENSIONS,
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): Promise<ProjectUsageMetricOverview> {
  if (!projectId) {
    return { changePercent: 0, chartPoints: [], topEndpoints: [] }
  }

  const results = await Promise.all(
    metrics.map((metric) =>
      fetchUsageMetricSeries(
        projectId,
        metric,
        dateRange,
        interval,
        dimensions,
        breakdownLimit,
      ),
    ),
  )

  return mergeUsageMetricSeries(results)
}

/** Single-metric fetch with separate current/previous series (for multi-line charts). */
export async function fetchProjectUsageMetricSeriesOverview(
  projectId: string,
  metric: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  dimensions: readonly string[] = TOP_ENDPOINTS_DIMENSIONS,
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): Promise<UsageMetricSeriesResult> {
  if (!projectId) {
    return { chartPoints: [], previousChartPoints: [], topEndpoints: [] }
  }

  return fetchUsageMetricSeries(
    projectId,
    metric,
    dateRange,
    interval,
    dimensions,
    breakdownLimit,
  )
}

/** Fill missing interval buckets with zero so the chart spans the full date range. */
export function fillChartPointsGaps(
  merged: Map<string, number>,
  from: Date,
  to: Date,
  interval: UsageChartInterval,
): UsageChartPoint[] {
  const lookup = buildBucketLookup(merged, interval)
  const points: UsageChartPoint[] = []
  let cursor = getIntervalStart(from, interval)
  const endCursor = getIntervalStart(to, interval)

  while (cursor.getTime() <= endCursor.getTime()) {
    const key = cursor.getTime()
    points.push({
      date: formatChartPointLabel(cursor, interval, from, to),
      day: cursor,
      total: lookup.get(key) ?? 0,
    })
    cursor = advanceIntervalCursor(cursor, interval)
  }

  return points
}

function splitGroupsByPeriod(
  groups: Models.UsageGroup[],
  currentFrom: Date,
): {
  current: Models.UsageGroup[]
  previous: Models.UsageGroup[]
} {
  const currentFromMs = currentFrom.getTime()
  const current: Models.UsageGroup[] = []
  const previous: Models.UsageGroup[] = []

  for (const group of groups) {
    const groupMs = parseISO(group.time).getTime()
    if (groupMs >= currentFromMs) {
      current.push(group)
    } else {
      previous.push(group)
    }
  }

  return { current, previous }
}

async function listUsageEventGroups(
  projectId: string,
  params: ListUsageEventGroupsParams,
): Promise<Models.UsageGroup[]> {
  const projectSdk = sdk.forProject(projectId)
  const request: {
    metric: string
    interval?: string
    startAt: string
    endAt: string
    dimensions?: string[]
    resource?: string
    resourceId?: string
  } = {
    metric: params.metric,
    startAt: params.startAt,
    endAt: params.endAt,
  }

  if (params.interval) {
    request.interval = params.interval
  }
  if (params.dimensions?.length) {
    request.dimensions = params.dimensions
  }
  if (params.resource) {
    request.resource = params.resource
  }
  if (params.resourceId) {
    request.resourceId = params.resourceId
  }

  const response = await projectSdk.usage.listEvents(request)
  return response.groups ?? []
}

async function listUsageEventGroupsForMetrics(
  projectId: string,
  metrics: readonly string[],
  params: Omit<ListUsageEventGroupsParams, 'metric'>,
): Promise<Models.UsageGroup[]> {
  const results = await Promise.all(
    metrics.map((metric) =>
      listUsageEventGroups(projectId, { ...params, metric }),
    ),
  )
  return results.flat()
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
 * Chart-only fetch (no dimensions). Used by org project list sparklines.
 */
export async function fetchProjectUsageChartOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<ProjectUsageChartOverview> {
  if (!projectId) {
    return {
      changePercent: 0,
      chartPoints: [],
    }
  }

  const {
    from,
    to,
    previousFrom,
    previousTo,
    interval: resolvedInterval,
  } = resolveOverviewUsagePeriod(dateRange, interval)

  const groups = await listUsageEventGroupsForMetrics(projectId, metrics, {
    interval: resolvedInterval,
    startAt: previousFrom.toISOString(),
    endAt: to.toISOString(),
  })

  const { current, previous } = splitGroupsByPeriod(groups, from)
  const currentMerged = mergeValuesByTime(current)
  const previousMerged = mergeValuesByTime(previous)
  const chartPoints = fillChartPointsGaps(
    currentMerged,
    from,
    to,
    resolvedInterval,
  )
  const previousChartPoints = fillChartPointsGaps(
    previousMerged,
    previousFrom,
    previousTo,
    resolvedInterval,
  )

  return {
    changePercent: computeChangePercent(
      sumUsageChartPoints(chartPoints),
      sumUsageChartPoints(previousChartPoints),
    ),
    chartPoints,
  }
}

/** @deprecated Use fetchProjectUsageMetricsOverview for chart + breakdown together. */
export async function fetchProjectUsageTopEndpoints(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<ProjectUsageTopEndpointsOverview> {
  const overview = await fetchProjectUsageMetricsOverview(
    projectId,
    dateRange,
    metrics,
    interval,
  )

  return { topEndpoints: overview.topEndpoints }
}
