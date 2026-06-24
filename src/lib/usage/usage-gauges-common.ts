import { parseISO } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import { areUsageBreakdownQueriesEnabled } from '@/lib/debug-overrides'
import {
  computeChangePercent,
  fillGaugeChartPointsGaps,
  resolveOverviewUsagePeriod,
  type ProjectUsageChartOverview,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'

export type { UsageTopEndpoint, UsageChartInterval } from '@/lib/usage/usage-events-common'

export interface ProjectUsageGaugeOverview {
  changePercent: number
  latestValue: number
  topConsumers: UsageTopEndpoint[]
}

interface ListUsageGaugeGroupsParams {
  metrics: readonly string[]
  interval?: UsageChartInterval
  startAt: string
  endAt: string
  dimensions?: string[]
  resourceId?: string
  teamId?: string
}

async function listUsageGaugeGroupsByMetric(
  projectId: string,
  params: ListUsageGaugeGroupsParams,
): Promise<Map<string, Models.UsageDataPoint[]>> {
  if (params.metrics.length === 0) {
    return new Map()
  }

  const projectSdk = sdk.forProject(projectId)
  const request: {
    metrics: string[]
    interval?: string
    startAt: string
    endAt: string
    dimensions?: string[]
    resourceId?: string
    teamId?: string
  } = {
    metrics: [...params.metrics],
    startAt: params.startAt,
    endAt: params.endAt,
  }

  if (params.interval) {
    request.interval = params.interval
  }
  if (params.dimensions?.length) {
    request.dimensions = params.dimensions
  }
  if (params.resourceId) {
    request.resourceId = params.resourceId
  }
  if (params.teamId) {
    request.teamId = params.teamId
  }

  const response = await projectSdk.usage.listGauges(request)
  const result = new Map<string, Models.UsageDataPoint[]>()

  for (const metric of params.metrics) {
    result.set(
      metric,
      response.metrics?.find((entry) => entry.metric === metric)?.points ?? [],
    )
  }

  return result
}

async function listUsageGaugeGroups(
  projectId: string,
  params: Omit<ListUsageGaugeGroupsParams, 'metrics'> & { metric: string },
): Promise<Models.UsageDataPoint[]> {
  const groupsByMetric = await listUsageGaugeGroupsByMetric(projectId, {
    ...params,
    metrics: [params.metric],
  })

  return groupsByMetric.get(params.metric) ?? []
}

function mapGaugeResourceBreakdownGroups(
  groups: Models.UsageDataPoint[],
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): UsageTopEndpoint[] {
  const latestByResource = new Map<string, { value: number; timeMs: number }>()

  for (const group of groups) {
    const resourceId = group.resourceId?.trim()
    if (!resourceId) continue

    const timeMs = parseISO(group.time).getTime()
    const existing = latestByResource.get(resourceId)
    if (!existing || timeMs >= existing.timeMs) {
      latestByResource.set(resourceId, { value: group.value, timeMs })
    }
  }

  return Array.from(latestByResource.entries())
    .sort((a, b) => b[1].value - a[1].value)
    .slice(0, limit)
    .map(([resourceId, { value }]) => ({
      id: resourceId,
      method: '',
      statusCode: 0,
      path: resourceId,
      count: value,
    }))
}

/** Latest gauge snapshot in a window (most recent by time, client-side). */
function getLatestUsageGaugeValueInRange(
  groups: Models.UsageDataPoint[],
  startAt: Date,
  endAt: Date,
): number {
  const startMs = startAt.getTime()
  const endMs = endAt.getTime()
  let latestValue = 0
  let latestTimeMs = -1

  for (const group of groups) {
    const timeMs = parseISO(group.time).getTime()
    if (timeMs < startMs || timeMs > endMs) {
      continue
    }

    if (timeMs >= latestTimeMs) {
      latestTimeMs = timeMs
      latestValue = group.value
    }
  }

  return latestValue
}

/** Latest gauge snapshot in a window (most recent by time, client-side). */
export async function fetchLatestUsageGaugeValue(
  projectId: string,
  metric: string,
  startAt: Date,
  endAt: Date,
): Promise<number> {
  const groups = await listUsageGaugeGroups(projectId, {
    metric,
    startAt: startAt.toISOString(),
    endAt: endAt.toISOString(),
  })

  return getLatestUsageGaugeValueInRange(groups, startAt, endAt)
}

/** Flat top-N gauge breakdown (no interval), sorted client-side. */
export async function fetchUsageGaugeBreakdown(
  projectId: string,
  metric: string,
  startAt: Date,
  endAt: Date,
  dimensions: readonly string[],
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): Promise<UsageTopEndpoint[]> {
  const groups = await listUsageGaugeGroups(projectId, {
    metric,
    dimensions: [...dimensions],
    startAt: startAt.toISOString(),
    endAt: endAt.toISOString(),
  })

  return mapGaugeResourceBreakdownGroups(groups, breakdownLimit)
}

async function listUsageGaugeGroupsForMetrics(
  projectId: string,
  metrics: readonly string[],
  params: Omit<ListUsageGaugeGroupsParams, 'metrics'>,
): Promise<Models.UsageDataPoint[]> {
  const groupsByMetric = await listUsageGaugeGroupsByMetric(projectId, {
    ...params,
    metrics,
  })

  return Array.from(groupsByMetric.values()).flat()
}

function mergeGaugeValuesByTime(
  groups: Models.UsageDataPoint[],
): Map<string, number> {
  const merged = new Map<string, number>()
  for (const group of groups) {
    merged.set(group.time, (merged.get(group.time) ?? 0) + group.value)
  }
  return merged
}

/** Current and previous gauge chart series (merged per bucket). */
export async function fetchProjectUsageGaugeChartSeries(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<{
  chartPoints: ProjectUsageChartOverview['chartPoints']
  previousChartPoints: ProjectUsageChartOverview['chartPoints']
}> {
  if (!projectId || metrics.length === 0) {
    return { chartPoints: [], previousChartPoints: [] }
  }

  const {
    from,
    to,
    previousFrom,
    previousTo,
    interval: resolvedInterval,
  } = resolveOverviewUsagePeriod(dateRange, interval)

  const [currentGroups, previousGroups] = await Promise.all([
    listUsageGaugeGroupsForMetrics(projectId, metrics, {
      interval: resolvedInterval,
      startAt: from.toISOString(),
      endAt: to.toISOString(),
    }),
    listUsageGaugeGroupsForMetrics(projectId, metrics, {
      interval: resolvedInterval,
      startAt: previousFrom.toISOString(),
      endAt: previousTo.toISOString(),
    }),
  ])

  const currentMerged = mergeGaugeValuesByTime(currentGroups)
  const previousMerged = mergeGaugeValuesByTime(previousGroups)

  return {
    chartPoints: fillGaugeChartPointsGaps(
      currentMerged,
      from,
      to,
      resolvedInterval,
    ),
    previousChartPoints: fillGaugeChartPointsGaps(
      previousMerged,
      previousFrom,
      previousTo,
      resolvedInterval,
    ),
  }
}

/** Time-series chart for one or more gauge metrics (merged per bucket). */
export async function fetchProjectUsageGaugesChartOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<ProjectUsageChartOverview> {
  if (!projectId || metrics.length === 0) {
    return { changePercent: 0, chartPoints: [] }
  }

  const { chartPoints, previousChartPoints } =
    await fetchProjectUsageGaugeChartSeries(
      projectId,
      dateRange,
      metrics,
      interval,
    )

  const currentLatest =
    chartPoints.length > 0 ? chartPoints[chartPoints.length - 1].total : 0
  const previousLatest =
    previousChartPoints.length > 0
      ? previousChartPoints[previousChartPoints.length - 1].total
      : 0

  return {
    changePercent: computeChangePercent(currentLatest, previousLatest),
    chartPoints,
  }
}

/** Latest file storage value + top buckets for the overview storage tab. */
export async function fetchProjectUsageGaugeSnapshotOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  metric: string,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  breakdown?: {
    dimensions: readonly string[]
    limit?: number
  },
  options?: { includeBreakdown?: boolean },
): Promise<ProjectUsageGaugeOverview> {
  if (!projectId) {
    return { changePercent: 0, latestValue: 0, topConsumers: [] }
  }

  const { from, to, previousFrom, previousTo } = resolveOverviewUsagePeriod(
    dateRange,
    interval,
  )

  const includeBreakdown =
    options?.includeBreakdown !== false &&
    breakdown != null &&
    areUsageBreakdownQueriesEnabled()

  const [snapshotGroups, topConsumers] = await Promise.all([
    listUsageGaugeGroups(projectId, {
      metric,
      startAt: previousFrom.toISOString(),
      endAt: to.toISOString(),
    }),
    includeBreakdown
      ? fetchUsageGaugeBreakdown(
          projectId,
          metric,
          from,
          to,
          breakdown!.dimensions,
          breakdown!.limit ?? OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
        )
      : Promise.resolve([]),
  ])

  const latestValue = getLatestUsageGaugeValueInRange(snapshotGroups, from, to)
  const previousValue = getLatestUsageGaugeValueInRange(
    snapshotGroups,
    previousFrom,
    previousTo,
  )

  return {
    latestValue,
    changePercent: computeChangePercent(latestValue, previousValue),
    topConsumers,
  }
}
