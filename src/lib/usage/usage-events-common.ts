import {
  addDays,
  addHours,
  addMinutes,
  differenceInCalendarDays,
  endOfDay,
  format,
  isSameDay,
  parseISO,
  startOfDay,
  startOfHour,
  startOfMinute,
  subDays,
} from 'date-fns'
import {
  DEFAULT_USAGE_LOG_RETENTION_HOURS,
  getUsageLogRetentionFloor,
} from '@/lib/usage/usage-log-retention'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import type { DateRange } from 'react-day-picker'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { buildUsageResourceFilterQueries } from '@/lib/usage/usage-resource-queries'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import {
  isFullCalendarDayRange,
  resolveUsageDateBounds,
} from '@/lib/usage/usage-date-range'
import {
  OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  resolveUsageListOrder,
} from '@/lib/usage/breakdown-limits'
import { areUsageBreakdownQueriesEnabled } from '@/lib/debug-overrides'
import { isUsageProjectResourceType } from '@/lib/usage/usage-resource-filters'
import { isScreenshotModeActive } from '@/lib/screenshot-mode'

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
  resourceType?: string
}

/** Dimensions supported by usage.listEvents for event metrics. */
export type UsageEventBreakdownDimension =
  | 'path'
  | 'method'
  | 'status'
  | 'service'
  | 'country'
  | 'city'
  | 'hostname'
  | 'ip'
  | 'osName'
  | 'clientType'
  | 'clientName'
  | 'deviceName'
  | 'teamId'
  | 'resourceId'
  | 'resourceType'
  /** Composite breakdown by resource type and ID (listEvents dimensions: resourceId + resourceType). */
  | 'resource'
  /** Composite breakdown by SDK and version (listEvents dimensions: sdk + sdkVersion). */
  | 'sdk'

export const USAGE_RESOURCE_BREAKDOWN_DIMENSIONS = [
  'resourceId',
  'resourceType',
] as const

export const USAGE_SDK_BREAKDOWN_DIMENSIONS = ['sdk', 'sdkVersion'] as const

/** Closed listEvents dimension contract used at the SDK boundary. */
export type UsageEventApiDimension =
  | 'path'
  | 'method'
  | 'status'
  | 'service'
  | 'resourceType'
  | 'country'
  | 'continentCode'
  | 'city'
  | 'region'
  | 'hostname'
  | 'ip'
  | 'osName'
  | 'clientType'
  | 'clientName'
  | 'deviceName'
  | 'sdk'
  | 'sdkVersion'
  | 'teamId'
  | 'resourceId'

export interface UsageBreakdownItem {
  id: string
  label: string
  count: number
  resourceId?: string
  resourceType?: string
  sdk?: string
  sdkVersion?: string
}

function getUsageDataPointBreakdownLabel(
  point: Models.UsageDataPoint,
  dimension: UsageEventBreakdownDimension,
): string {
  switch (dimension) {
    case 'path':
      return point.path?.trim() || '/'
    case 'method':
      return point.method?.trim() || 'Unknown'
    case 'status':
      return point.status?.trim() || 'Unknown'
    case 'service':
      return point.service?.trim() || 'Unknown'
    case 'country':
      return point.country?.trim() || 'Unknown'
    case 'city':
      return point.city?.trim() || 'Unknown'
    case 'hostname':
      return point.hostname?.trim() || 'Unknown'
    case 'ip':
      return point.ip?.trim() || 'Unknown'
    case 'osName':
      return point.osName?.trim() || 'Unknown'
    case 'clientType':
      return point.clientType?.trim() || 'Unknown'
    case 'clientName':
      return point.clientName?.trim() || 'Unknown'
    case 'deviceName':
      return point.deviceName?.trim() || 'Unknown'
    case 'teamId':
      return point.teamId?.trim() || 'Unknown'
    case 'resourceId':
      return point.resourceId?.trim() || 'Unknown'
    case 'resourceType':
      return point.resourceType?.trim() || 'Unknown'
    case 'resource':
      return point.resourceId?.trim() || 'Unknown'
    case 'sdk':
      return formatSdkBreakdownLabel(
        point.sdk?.trim() || 'Unknown',
        point.sdkVersion?.trim() || '',
      )
    default:
      return 'Unknown'
  }
}

function formatSdkBreakdownLabel(sdk: string, sdkVersion: string): string {
  const trimmedSdk = sdk.trim() || 'Unknown'
  const trimmedVersion = sdkVersion.trim()
  if (!trimmedVersion || trimmedVersion === 'Unknown') {
    return trimmedSdk
  }
  return `${trimmedSdk}@${trimmedVersion}`
}

function getUsageBreakdownItemMergeKey(item: UsageBreakdownItem): string {
  if (isUsageProjectResourceType(item.resourceType)) {
    return 'project'
  }
  if (item.resourceId && item.resourceType) {
    return `${item.resourceType}\0${item.resourceId}`
  }
  if (item.sdk !== undefined) {
    return `sdk\0${item.sdk}\0${item.sdkVersion ?? ''}`
  }
  return item.label
}

function mapBreakdownGroupsForResourceDimensions(
  groups: Models.UsageDataPoint[],
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): UsageBreakdownItem[] {
  const items = groups.map((group, index) => {
    const resourceType = group.resourceType?.trim() || 'Unknown'
    if (isUsageProjectResourceType(resourceType)) {
      return {
        id: `resource-project-${index}`,
        label: 'project',
        count: group.value,
        resourceType: 'project',
      }
    }

    const resourceId = group.resourceId?.trim() || 'Unknown'
    return {
      id: `resource-${resourceType}-${resourceId}-${index}`,
      label: resourceId,
      count: group.value,
      resourceId,
      resourceType,
    }
  })

  return mergeUsageBreakdownItems([items], limit)
}

function mapBreakdownGroupsForSdkDimensions(
  groups: Models.UsageDataPoint[],
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): UsageBreakdownItem[] {
  const items = groups.map((group, index) => {
    const sdk = group.sdk?.trim() || 'Unknown'
    const sdkVersion = group.sdkVersion?.trim() || ''
    const label = formatSdkBreakdownLabel(sdk, sdkVersion)
    return {
      id: `sdk-${sdk}-${sdkVersion || 'unknown'}-${index}`,
      label,
      count: group.value,
      sdk,
      sdkVersion: sdkVersion || undefined,
    }
  })

  return mergeUsageBreakdownItems([items], limit)
}

function mapBreakdownGroupsForDimension(
  groups: Models.UsageDataPoint[],
  dimension: UsageEventBreakdownDimension,
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): UsageBreakdownItem[] {
  if (dimension === 'resource') {
    return mapBreakdownGroupsForResourceDimensions(groups, limit)
  }
  if (dimension === 'sdk') {
    return mapBreakdownGroupsForSdkDimensions(groups, limit)
  }

  const items = groups.map((group, index) => {
    const label = getUsageDataPointBreakdownLabel(group, dimension)
    return {
      id: `${dimension}-${label}-${index}`,
      label,
      count: group.value,
    }
  })

  return items.sort((a, b) => b.count - a.count).slice(0, limit)
}

/** Merge breakdown rows from multiple metrics (e.g. inbound + outbound bandwidth). */
export function mergeUsageBreakdownItems(
  lists: UsageBreakdownItem[][],
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): UsageBreakdownItem[] {
  const grouped = new Map<string, UsageBreakdownItem>()

  for (const list of lists) {
    for (const item of list) {
      const key = getUsageBreakdownItemMergeKey(item)
      const existing = grouped.get(key)
      if (existing) {
        existing.count += item.count
        continue
      }
      grouped.set(key, { ...item })
    }
  }

  return Array.from(grouped.values())
    .sort((a, b) => b.count - a.count)
    .slice(0, limit)
}

/** Flat top-N breakdown for a single listEvents dimension (no interval). */
export async function fetchProjectUsageEventBreakdown(
  projectId: string,
  metric: string,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  queries?: string[],
  resourceId?: string,
  resourceType?: string,
): Promise<UsageBreakdownItem[]> {
  if (!projectId) {
    return []
  }

  const { from, to } = resolveOverviewUsagePeriod(dateRange)

  const dimensions =
    dimension === 'resource'
      ? [...USAGE_RESOURCE_BREAKDOWN_DIMENSIONS]
      : dimension === 'sdk'
        ? [...USAGE_SDK_BREAKDOWN_DIMENSIONS]
        : [dimension]

  const groups = await listUsageEventGroupsForMetric(projectId, metric, {
    dimensions,
    startAt: from.toISOString(),
    endAt: to.toISOString(),
    queries,
    resourceId,
    resourceType,
    limit: breakdownLimit,
  })

  return mapBreakdownGroupsForDimension(groups, dimension, breakdownLimit)
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

export type UsagePeriodComparisonMode = 'prior_window' | 'first_half'

export interface OverviewUsagePeriod {
  from: Date
  to: Date
  previousFrom: Date
  previousTo: Date
  interval: UsageChartInterval
  /** When `first_half`, change % compares the second half of the chart to the first half fetch. */
  comparisonMode: UsagePeriodComparisonMode
}

export function resolveDateBounds(dateRange: DateRange | undefined): {
  from: Date
  to: Date
} {
  return resolveUsageDateBounds(dateRange)
}

function resolveFirstHalfComparisonPeriod(
  from: Date,
  to: Date,
  calendarRange: boolean,
): { previousFrom: Date; previousTo: Date } {
  if (calendarRange) {
    const rangeDays = Math.max(1, differenceInCalendarDays(to, from) + 1)
    const halfDays = Math.max(1, Math.floor(rangeDays / 2))
    return {
      previousFrom: from,
      previousTo: endOfDay(addDays(from, halfDays - 1)),
    }
  }

  const midMs = from.getTime() + (to.getTime() - from.getTime()) / 2
  return {
    previousFrom: from,
    previousTo: new Date(midMs),
  }
}

export function resolveOverviewUsagePeriod(
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  logRetentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
): OverviewUsagePeriod {
  const { from, to } = resolveUsageDateBounds(dateRange)
  const calendarRange = isFullCalendarDayRange(from, to)

  let previousFrom: Date
  let previousTo: Date

  if (calendarRange) {
    const rangeDays = Math.max(1, differenceInCalendarDays(to, from) + 1)
    previousTo = endOfDay(subDays(from, 1))
    previousFrom = startOfDay(subDays(previousTo, rangeDays - 1))
  } else {
    const durationMs = to.getTime() - from.getTime()
    previousTo = new Date(from.getTime())
    previousFrom = new Date(from.getTime() - durationMs)
  }

  let comparisonMode: UsagePeriodComparisonMode = 'prior_window'

  if (logRetentionHours > 0) {
    const retentionFloor = getUsageLogRetentionFloor(logRetentionHours)
    if (previousFrom.getTime() < retentionFloor.getTime()) {
      const firstHalf = resolveFirstHalfComparisonPeriod(
        from,
        to,
        calendarRange,
      )
      previousFrom = firstHalf.previousFrom
      previousTo = firstHalf.previousTo
      comparisonMode = 'first_half'
    }
  }

  return { from, to, previousFrom, previousTo, interval, comparisonMode }
}

/**
 * First half of a filled series. Used when the prior window falls outside log
 * retention so change % compares the second half to this slice instead of
 * issuing a second API request for the same buckets.
 */
export function getUsageChartFirstHalfPoints(
  chartPoints: UsageChartPoint[],
): UsageChartPoint[] {
  const midIndex = Math.ceil(chartPoints.length / 2)
  return chartPoints.slice(0, midIndex)
}

export function sumUsageChartPointsForComparison(
  chartPoints: UsageChartPoint[],
  comparisonMode: UsagePeriodComparisonMode,
): number {
  if (comparisonMode === 'prior_window') {
    return sumUsageChartPoints(chartPoints)
  }

  const midIndex = Math.ceil(chartPoints.length / 2)
  return sumUsageChartPoints(chartPoints.slice(midIndex))
}

function get15MinuteIntervalStart(date: Date): Date {
  const start = startOfMinute(date)
  start.setMinutes(start.getMinutes() - (start.getMinutes() % 15), 0, 0)
  return start
}

function getIntervalStart(date: Date, interval: UsageChartInterval): Date {
  if (interval === '15m') return get15MinuteIntervalStart(date)
  if (interval === '1h') return startOfHour(date)
  return startOfDay(date)
}

function advanceIntervalCursor(date: Date, interval: UsageChartInterval): Date {
  if (interval === '15m') return addMinutes(date, 15)
  if (interval === '1h') return addHours(date, 1)
  return addDays(date, 1)
}

export interface FetchUsageOverviewOptions {
  includeBreakdown?: boolean
  queries?: string[]
  /** Scope event/gauge metrics to a single resource (e.g. database id). */
  resourceId?: string
  /** Usage resource type filter (e.g. `dedicatedDatabases`). Sent via queries[]. */
  resourceType?: string
  /**
   * Cluster node for multi-node dedicated database gauges.
   * 0 = primary, 1+ = replicas. Sent as `equal("ordinal", …)`.
   */
  ordinal?: number
  /** Plan log retention in hours; defaults to Pro (30 days). */
  logRetentionHours?: number
}

interface ListUsageEventGroupsParams {
  metrics: readonly string[]
  interval?: UsageChartInterval
  startAt: string
  endAt: string
  dimensions?: UsageEventApiDimension[]
  queries?: string[]
  resourceId?: string
  resourceType?: string
  /**
   * Max rows from listEvents. For flat (no-interval) top-N breakdowns, pass the
   * UI limit so the API returns the highest-value groups (`orderBy=value` desc).
   * Charts omit this and use USAGE_API_MAX_LIMIT with chronological asc order.
   */
  limit?: number
}

function mergeValuesByTime(
  groups: Models.UsageDataPoint[],
): Map<string, number> {
  const merged = new Map<string, number>()
  for (const group of groups) {
    merged.set(group.time, (merged.get(group.time) ?? 0) + group.value)
  }
  return merged
}

function normalizeBucketTime(date: Date, interval: UsageChartInterval): Date {
  return getIntervalStart(date, interval)
}

function formatChartPointLabel(
  day: Date,
  interval: UsageChartInterval,
  rangeFrom: Date,
  rangeTo: Date,
): string {
  if (interval === '15m' || interval === '1h') {
    const spansMultipleDays = !isSameDay(rangeFrom, rangeTo)
    return spansMultipleDays
      ? formatLocalizedDate(day, 'd MMM HH:mm')
      : format(day, 'HH:mm')
  }
  return formatLocalizedDate(day, 'd MMM')
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
  comparisonMode: UsagePeriodComparisonMode = 'prior_window',
): ProjectUsageMetricOverview {
  if (results.length === 0) {
    return { changePercent: 0, chartPoints: [], topEndpoints: [] }
  }

  const chartPoints = mergeChartPointsSeries(
    results.map((result) => result.chartPoints),
  )
  const previousChartPoints = mergeChartPointsSeries(
    results.map((result) => result.previousChartPoints),
  )

  return {
    chartPoints,
    topEndpoints: mergeTopEndpoints(
      results.map((result) => result.topEndpoints),
    ),
    changePercent: computeChangePercent(
      sumUsageChartPointsForComparison(chartPoints, comparisonMode),
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

const TOP_ENDPOINTS_DIMENSIONS = ['path'] as const

function mapBreakdownGroupsToEndpoints(
  groups: Models.UsageDataPoint[],
  dimensions: readonly string[],
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): UsageTopEndpoint[] {
  let items: UsageTopEndpoint[]

  if (
    dimensions.length === 2 &&
    dimensions.includes('resourceId') &&
    dimensions.includes('resourceType')
  ) {
    items = groups.map((group, index) => {
      const resourceType = group.resourceType?.trim() || ''
      if (isUsageProjectResourceType(resourceType)) {
        return {
          id: `project-${index}`,
          method: '',
          statusCode: 0,
          path: 'project',
          count: group.value,
          resourceType: 'project',
        }
      }
      const resourceId = group.resourceId?.trim() || ''
      return {
        id: resourceId || `resource-${index}`,
        method: '',
        statusCode: 0,
        path: resourceId,
        count: group.value,
        resourceType,
      }
    })
  } else if (dimensions.length === 1 && dimensions[0] === 'resourceId') {
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
  } else if (dimensions.length === 1 && dimensions[0] === 'resourceType') {
    items = groups.map((group, index) => {
      const resource = group.resourceType?.trim() || ''
      return {
        id: resource || `resource-type-${index}`,
        method: '',
        statusCode: 0,
        path: resource,
        count: group.value,
      }
    })
  } else {
    items = groups.map((group, index) => {
      const path = group.path || '/'
      return {
        id: path || `path-${index}`,
        method: '',
        statusCode: 0,
        path,
        count: group.value,
      }
    })
  }

  return items.sort((a, b) => b.count - a.count).slice(0, limit)
}

export async function fetchUsageMetricsChartSeriesByMetric(
  projectId: string,
  metrics: readonly string[],
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  queries?: string[],
  logRetentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
  resourceId?: string,
  resourceType?: string,
): Promise<
  Map<
    string,
    Pick<UsageMetricSeriesResult, 'chartPoints' | 'previousChartPoints'>
  >
> {
  if (metrics.length === 0) {
    return new Map()
  }

  const {
    from,
    to,
    previousFrom,
    previousTo,
    interval: resolvedInterval,
    comparisonMode,
  } = resolveOverviewUsagePeriod(dateRange, interval, logRetentionHours)

  if (isScreenshotModeActive()) {
    const result = new Map<
      string,
      Pick<UsageMetricSeriesResult, 'chartPoints' | 'previousChartPoints'>
    >()
    for (const metric of metrics) {
      const chartPoints = buildScreenshotModeChartPoints(
        from,
        to,
        resolvedInterval,
        metric,
      )
      const previousChartPoints =
        comparisonMode === 'first_half'
          ? getUsageChartFirstHalfPoints(chartPoints)
          : buildScreenshotModeChartPoints(
              previousFrom,
              previousTo,
              resolvedInterval,
              metric,
              { quieter: true },
            )
      result.set(metric, { chartPoints, previousChartPoints })
    }
    return result
  }

  const currentByMetric = await listUsageEventGroupsByMetric(projectId, {
    metrics,
    interval: resolvedInterval,
    startAt: from.toISOString(),
    endAt: to.toISOString(),
    queries,
    resourceId,
    resourceType,
  })

  // First-half comparison reuses the current series; a second fetch would
  // request the same buckets we already have.
  const previousByMetric =
    comparisonMode === 'prior_window'
      ? await listUsageEventGroupsByMetric(projectId, {
          metrics,
          interval: resolvedInterval,
          startAt: previousFrom.toISOString(),
          endAt: previousTo.toISOString(),
          queries,
          resourceId,
          resourceType,
        })
      : null

  const result = new Map<
    string,
    Pick<UsageMetricSeriesResult, 'chartPoints' | 'previousChartPoints'>
  >()

  for (const metric of metrics) {
    const currentMerged = mergeValuesByTime(currentByMetric.get(metric) ?? [])
    const chartPoints = fillChartPointsGaps(
      currentMerged,
      from,
      to,
      resolvedInterval,
    )
    const previousChartPoints =
      comparisonMode === 'first_half'
        ? getUsageChartFirstHalfPoints(chartPoints)
        : fillChartPointsGaps(
            mergeValuesByTime(previousByMetric?.get(metric) ?? []),
            previousFrom,
            previousTo,
            resolvedInterval,
          )
    result.set(metric, {
      chartPoints,
      previousChartPoints,
    })
  }

  return result
}

async function fetchUsageMetricChartSeries(
  projectId: string,
  metric: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  queries?: string[],
  logRetentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
  resourceId?: string,
  resourceType?: string,
): Promise<
  Pick<UsageMetricSeriesResult, 'chartPoints' | 'previousChartPoints'>
> {
  const chartSeriesByMetric = await fetchUsageMetricsChartSeriesByMetric(
    projectId,
    [metric],
    dateRange,
    interval,
    queries,
    logRetentionHours,
    resourceId,
    resourceType,
  )

  return (
    chartSeriesByMetric.get(metric) ?? {
      chartPoints: [],
      previousChartPoints: [],
    }
  )
}

export async function fetchUsageMetricsBreakdownByMetric(
  projectId: string,
  metrics: readonly string[],
  dateRange: DateRange | undefined,
  dimensions: readonly UsageEventApiDimension[],
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  queries?: string[],
  resourceId?: string,
  resourceType?: string,
): Promise<Map<string, UsageTopEndpoint[]>> {
  if (metrics.length === 0) {
    return new Map()
  }

  // listEvents applies `limit` to the whole request. Batching metrics (e.g.
  // network.inbound + network.outbound) with a small top-N limit under-fills
  // each metric and yields incomplete merged lists on the overview. Fetch each
  // metric separately, matching fetchProjectBandwidthBreakdown on the usage page.
  if (metrics.length > 1) {
    const entries = await Promise.all(
      metrics.map(async (metric) => {
        const breakdown = await fetchUsageMetricsBreakdownByMetric(
          projectId,
          [metric],
          dateRange,
          dimensions,
          breakdownLimit,
          queries,
          resourceId,
          resourceType,
        )
        return [metric, breakdown.get(metric) ?? []] as const
      }),
    )
    return new Map(entries)
  }

  const { from, to } = resolveOverviewUsagePeriod(dateRange)
  const groupsByMetric = await listUsageEventGroupsByMetric(projectId, {
    metrics,
    dimensions: [...dimensions],
    startAt: from.toISOString(),
    endAt: to.toISOString(),
    queries,
    resourceId,
    resourceType,
    limit: breakdownLimit,
  })

  const result = new Map<string, UsageTopEndpoint[]>()
  for (const metric of metrics) {
    result.set(
      metric,
      mapBreakdownGroupsToEndpoints(
        groupsByMetric.get(metric) ?? [],
        dimensions,
        breakdownLimit,
      ),
    )
  }

  return result
}

async function fetchUsageMetricBreakdown(
  projectId: string,
  metric: string,
  dateRange: DateRange | undefined,
  dimensions: readonly UsageEventApiDimension[],
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  queries?: string[],
  resourceId?: string,
  resourceType?: string,
): Promise<UsageTopEndpoint[]> {
  const breakdownByMetric = await fetchUsageMetricsBreakdownByMetric(
    projectId,
    [metric],
    dateRange,
    dimensions,
    breakdownLimit,
    queries,
    resourceId,
    resourceType,
  )

  return breakdownByMetric.get(metric) ?? []
}

async function fetchUsageMetricSeries(
  projectId: string,
  metric: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  dimensions: readonly UsageEventApiDimension[] = TOP_ENDPOINTS_DIMENSIONS,
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  options?: FetchUsageOverviewOptions,
): Promise<UsageMetricSeriesResult> {
  const includeBreakdown =
    options?.includeBreakdown !== false && areUsageBreakdownQueriesEnabled()
  const queries = options?.queries
  const resourceId = options?.resourceId
  const resourceType = options?.resourceType
  const logRetentionHours =
    options?.logRetentionHours ?? DEFAULT_USAGE_LOG_RETENTION_HOURS
  const [chartSeries, topEndpoints] = await Promise.all([
    fetchUsageMetricChartSeries(
      projectId,
      metric,
      dateRange,
      interval,
      queries,
      logRetentionHours,
      resourceId,
      resourceType,
    ),
    dimensions.length > 0 && includeBreakdown
      ? fetchUsageMetricBreakdown(
          projectId,
          metric,
          dateRange,
          dimensions,
          breakdownLimit,
          queries,
          resourceId,
          resourceType,
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
  dimensions: readonly UsageEventApiDimension[] = TOP_ENDPOINTS_DIMENSIONS,
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectUsageMetricOverview> {
  if (!projectId) {
    return { changePercent: 0, chartPoints: [], topEndpoints: [] }
  }

  const includeBreakdown =
    options?.includeBreakdown !== false && areUsageBreakdownQueriesEnabled()
  const queries = options?.queries
  const resourceId = options?.resourceId
  const resourceType = options?.resourceType
  const logRetentionHours =
    options?.logRetentionHours ?? DEFAULT_USAGE_LOG_RETENTION_HOURS
  const { comparisonMode } = resolveOverviewUsagePeriod(
    dateRange,
    interval,
    logRetentionHours,
  )

  const [chartSeriesByMetric, breakdownByMetric] = await Promise.all([
    fetchUsageMetricsChartSeriesByMetric(
      projectId,
      metrics,
      dateRange,
      interval,
      queries,
      logRetentionHours,
      resourceId,
      resourceType,
    ),
    includeBreakdown && dimensions.length > 0
      ? fetchUsageMetricsBreakdownByMetric(
          projectId,
          metrics,
          dateRange,
          dimensions,
          breakdownLimit,
          queries,
          resourceId,
          resourceType,
        )
      : Promise.resolve(new Map<string, UsageTopEndpoint[]>()),
  ])

  const results = metrics.map((metric) => ({
    chartPoints: chartSeriesByMetric.get(metric)?.chartPoints ?? [],
    previousChartPoints:
      chartSeriesByMetric.get(metric)?.previousChartPoints ?? [],
    topEndpoints: breakdownByMetric.get(metric) ?? [],
  }))

  return mergeUsageMetricSeries(results, comparisonMode)
}

/** Single-metric fetch with separate current/previous series (for multi-line charts). */
export async function fetchProjectUsageMetricSeriesOverview(
  projectId: string,
  metric: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  dimensions: readonly UsageEventApiDimension[] = TOP_ENDPOINTS_DIMENSIONS,
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  options?: FetchUsageOverviewOptions,
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
    options,
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

/**
 * Fill gauge chart buckets by carrying snapshots across missing intervals.
 * Gauges report levels (MAU, storage, CPU %), not per-interval deltas - missing
 * buckets must not be treated as zero. Leading gaps before the first sample are
 * backfilled with the first known value so charts do not plot a fake 0%.
 */
export function fillGaugeChartPointsGaps(
  merged: Map<string, number>,
  from: Date,
  to: Date,
  interval: UsageChartInterval,
): UsageChartPoint[] {
  const lookup = buildBucketLookup(merged, interval)
  const skeleton: {
    date: string
    day: Date
    sample: number | undefined
  }[] = []
  let cursor = getIntervalStart(from, interval)
  const endCursor = getIntervalStart(to, interval)

  while (cursor.getTime() <= endCursor.getTime()) {
    const key = cursor.getTime()
    skeleton.push({
      date: formatChartPointLabel(cursor, interval, from, to),
      day: cursor,
      sample: lookup.get(key),
    })
    cursor = advanceIntervalCursor(cursor, interval)
  }

  let firstKnown: number | undefined
  for (const row of skeleton) {
    if (row.sample !== undefined) {
      firstKnown = row.sample
      break
    }
  }

  let lastKnown = firstKnown
  return skeleton.map((row) => {
    if (row.sample !== undefined) {
      lastKnown = row.sample
    }
    return {
      date: row.date,
      day: row.day,
      total: lastKnown ?? 0,
    }
  })
}

function hashScreenshotMetric(metric: string): number {
  let hash = 0
  for (let i = 0; i < metric.length; i++) {
    hash = (hash * 31 + metric.charCodeAt(i)) | 0
  }
  return Math.abs(hash)
}

function getScreenshotMetricScale(metric: string): {
  base: number
  amplitude: number
  gauge?: boolean
} {
  const m = metric.toLowerCase()
  if (
    m.includes('network') ||
    m.includes('bandwidth') ||
    m.includes('inbound') ||
    m.includes('outbound')
  ) {
    return { base: 920_000_000, amplitude: 380_000_000 }
  }
  if (m.includes('storage') || m.includes('imagestransformed')) {
    return { base: 48_000_000_000, amplitude: 6_000_000_000, gauge: true }
  }
  if (
    m.includes('gbhour') ||
    m.includes('gb-hour') ||
    m.includes('compute') ||
    m.includes('execution')
  ) {
    return { base: 14_500, amplitude: 4_800 }
  }
  if (m.includes('request') || m.includes('http')) {
    return { base: 128_000, amplitude: 42_000 }
  }
  if (m.includes('auth') || m.includes('mau') || m.includes('signup')) {
    return { base: 18_400, amplitude: 2_200, gauge: true }
  }
  if (
    m.includes('realtime') ||
    m.includes('message') ||
    m.includes('connection')
  ) {
    return { base: 64_000, amplitude: 22_000 }
  }
  if (m.includes('database') || m.includes('read') || m.includes('write')) {
    return { base: 240_000, amplitude: 80_000 }
  }
  if (m.includes('webhook') || m.includes('messaging') || m.includes('sms')) {
    return { base: 32_000, amplitude: 12_000 }
  }
  return { base: 72_000, amplitude: 24_000 }
}

/** Dense mock series for screenshot mode - same buckets/labels as live charts. */
export function buildScreenshotModeChartPoints(
  from: Date,
  to: Date,
  interval: UsageChartInterval,
  metric: string,
  options?: { quieter?: boolean; gauge?: boolean },
): UsageChartPoint[] {
  const skeleton = options?.gauge
    ? fillGaugeChartPointsGaps(new Map(), from, to, interval)
    : fillChartPointsGaps(new Map(), from, to, interval)

  if (skeleton.length === 0) return skeleton

  const scale = getScreenshotMetricScale(metric)
  const phase = (hashScreenshotMetric(metric) % 1000) / 100
  const quieter = options?.quieter ? 0.72 : 1
  const base = scale.base * quieter
  const amplitude = scale.amplitude * quieter

  return skeleton.map((point, index) => {
    const t = index / Math.max(skeleton.length - 1, 1)
    const wave = Math.sin(phase + t * Math.PI * 2.15) * amplitude
    const secondary = Math.sin(phase * 1.6 + index * 0.37) * amplitude * 0.22
    const trend = options?.gauge || scale.gauge ? base * 0.08 * t : 0
    const peak = !scale.gauge && t > 0.55 && t < 0.82 ? amplitude * 0.35 : 0
    return {
      ...point,
      total: Math.max(0, Math.round(base + wave + secondary + trend + peak)),
    }
  })
}

async function listUsageEventGroupsByMetric(
  projectId: string,
  params: ListUsageEventGroupsParams,
): Promise<Map<string, Models.UsageDataPoint[]>> {
  if (params.metrics.length === 0) {
    return new Map()
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = buildUsageResourceFilterQueries({
    queries: params.queries,
    resourceId: params.resourceId,
    resourceType: params.resourceType,
  })
  const { orderBy, orderDir, limit } = resolveUsageListOrder({
    interval: params.interval,
    hasDimensions: (params.dimensions?.length ?? 0) > 0,
    limit: params.limit,
  })
  const request: {
    metrics: string[]
    interval?: UsageChartInterval
    startAt: string
    endAt: string
    dimensions?: UsageEventApiDimension[]
    queries?: string[]
    orderBy?: 'time' | 'value'
    orderDir?: 'asc' | 'desc'
    limit?: number
  } = {
    metrics: [...params.metrics],
    startAt: params.startAt,
    endAt: params.endAt,
    orderBy,
    orderDir,
    limit,
  }

  if (params.interval) {
    request.interval = params.interval
  }
  if (params.dimensions?.length) {
    request.dimensions = params.dimensions
  }
  if (queries?.length) {
    request.queries = queries
  }

  const response = await projectSdk.usage.listEvents(request)
  const result = new Map<string, Models.UsageDataPoint[]>()

  for (const metric of params.metrics) {
    result.set(
      metric,
      response.metrics?.find((entry) => entry.metric === metric)?.points ?? [],
    )
  }

  return result
}

async function listUsageEventGroupsForMetric(
  projectId: string,
  metric: string,
  params: Omit<ListUsageEventGroupsParams, 'metrics'>,
): Promise<Models.UsageDataPoint[]> {
  const groupsByMetric = await listUsageEventGroupsByMetric(projectId, {
    ...params,
    metrics: [metric],
  })

  return groupsByMetric.get(metric) ?? []
}

async function listUsageEventGroupsForMetrics(
  projectId: string,
  metrics: readonly string[],
  params: Omit<ListUsageEventGroupsParams, 'metrics'>,
): Promise<Models.UsageDataPoint[]> {
  const groupsByMetric = await listUsageEventGroupsByMetric(projectId, {
    ...params,
    metrics,
  })

  return Array.from(groupsByMetric.values()).flat()
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
  queries?: string[],
  logRetentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
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
    comparisonMode,
  } = resolveOverviewUsagePeriod(dateRange, interval, logRetentionHours)

  if (isScreenshotModeActive()) {
    const metricKey = metrics.join('|') || 'requests'
    const chartPoints = buildScreenshotModeChartPoints(
      from,
      to,
      resolvedInterval,
      metricKey,
    )
    const previousChartPoints =
      comparisonMode === 'first_half'
        ? getUsageChartFirstHalfPoints(chartPoints)
        : buildScreenshotModeChartPoints(
            previousFrom,
            previousTo,
            resolvedInterval,
            metricKey,
            { quieter: true },
          )
    return {
      changePercent: computeChangePercent(
        sumUsageChartPointsForComparison(chartPoints, comparisonMode),
        sumUsageChartPoints(previousChartPoints),
      ),
      chartPoints,
    }
  }

  const currentGroups = await listUsageEventGroupsForMetrics(
    projectId,
    metrics,
    {
      interval: resolvedInterval,
      startAt: from.toISOString(),
      endAt: to.toISOString(),
      queries,
    },
  )

  const previousGroups =
    comparisonMode === 'prior_window'
      ? await listUsageEventGroupsForMetrics(projectId, metrics, {
          interval: resolvedInterval,
          startAt: previousFrom.toISOString(),
          endAt: previousTo.toISOString(),
          queries,
        })
      : []

  const chartPoints = fillChartPointsGaps(
    mergeValuesByTime(currentGroups),
    from,
    to,
    resolvedInterval,
  )
  const previousChartPoints =
    comparisonMode === 'first_half'
      ? getUsageChartFirstHalfPoints(chartPoints)
      : fillChartPointsGaps(
          mergeValuesByTime(previousGroups),
          previousFrom,
          previousTo,
          resolvedInterval,
        )

  return {
    changePercent: computeChangePercent(
      sumUsageChartPointsForComparison(chartPoints, comparisonMode),
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
