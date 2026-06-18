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
  resolveOverviewUsagePeriod,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'

export type { UsageTopEndpoint, UsageChartInterval } from '@/lib/usage/usage-events-common'

export interface ProjectUsageGaugeOverview {
  changePercent: number
  latestValue: number
  topConsumers: UsageTopEndpoint[]
}

interface ListUsageGaugeGroupsParams {
  metric: string
  interval?: UsageChartInterval
  startAt: string
  endAt: string
  dimensions?: string[]
  resourceId?: string
  teamId?: string
}

async function listUsageGaugeGroups(
  projectId: string,
  params: ListUsageGaugeGroupsParams,
): Promise<Models.UsageGroup[]> {
  const projectSdk = sdk.forProject(projectId)
  const request: {
    metric: string
    interval?: string
    startAt: string
    endAt: string
    dimensions?: string[]
    resourceId?: string
    teamId?: string
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
  if (params.resourceId) {
    request.resourceId = params.resourceId
  }
  if (params.teamId) {
    request.teamId = params.teamId
  }

  const response = await projectSdk.usage.listGauges(request)
  return response.groups ?? []
}

function mapGaugeResourceBreakdownGroups(
  groups: Models.UsageGroup[],
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

  let latestValue = 0
  let latestTimeMs = -1

  for (const group of groups) {
    const timeMs = parseISO(group.time).getTime()
    if (timeMs >= latestTimeMs) {
      latestTimeMs = timeMs
      latestValue = group.value
    }
  }

  return latestValue
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
): Promise<ProjectUsageGaugeOverview> {
  if (!projectId) {
    return { changePercent: 0, latestValue: 0, topConsumers: [] }
  }

  const { from, to, previousFrom, previousTo } = resolveOverviewUsagePeriod(
    dateRange,
    interval,
  )

  const breakdownEnabled =
    breakdown != null && areUsageBreakdownQueriesEnabled()

  const [latestValue, previousValue, topConsumers] = await Promise.all([
    fetchLatestUsageGaugeValue(projectId, metric, from, to),
    fetchLatestUsageGaugeValue(projectId, metric, previousFrom, previousTo),
    breakdownEnabled
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

  return {
    latestValue,
    changePercent: computeChangePercent(latestValue, previousValue),
    topConsumers,
  }
}
