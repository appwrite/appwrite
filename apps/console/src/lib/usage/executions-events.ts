import type { DateRange } from 'react-day-picker'
import {
  formatCompactCount,
  formatCompactCountAxis,
} from '@/lib/usage/format-metric'
import {
  fetchProjectUsageMetricsOverview,
  type FetchUsageOverviewOptions,
  type UsageChartInterval,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { COMPUTE_BREAKDOWN_RESOURCE_LIMIT } from '@/lib/usage/breakdown-limits'

/** Combined function and site execution counts (overview). */
export const EXECUTIONS_EVENT_METRICS = ['executions'] as const

/** Function execution counts. */
export const FUNCTION_EXECUTIONS_EVENT_METRICS = ['functions.executions'] as const

/** Site execution counts. */
export const SITE_EXECUTIONS_EVENT_METRICS = ['sites.executions'] as const

const EXECUTIONS_BREAKDOWN_DIMENSIONS = ['resourceId', 'resourceType'] as const

export type ExecutionsChartPoint = UsageChartPoint
export type ExecutionsTopConsumer = UsageTopEndpoint

export interface ProjectExecutionsOverview {
  changePercent: number
  chartPoints: ExecutionsChartPoint[]
  topConsumers: ExecutionsTopConsumer[]
}

export function formatExecutionsTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatExecutionsValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export { formatCompactCountAxis as formatExecutionsAxisValue } from '@/lib/usage/format-metric'
export { sumUsageChartPoints } from '@/lib/usage/usage-events-common'

async function fetchExecutionsOverviewForMetrics(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectExecutionsOverview> {
  const overview = await fetchProjectUsageMetricsOverview(
    projectId,
    dateRange,
    metrics,
    interval,
    EXECUTIONS_BREAKDOWN_DIMENSIONS,
    COMPUTE_BREAKDOWN_RESOURCE_LIMIT,
    options,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
    topConsumers: overview.topEndpoints,
  }
}

/** Chart series + server-side top resource breakdown (functions and sites). */
export async function fetchProjectExecutionsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectExecutionsOverview> {
  return fetchExecutionsOverviewForMetrics(
    projectId,
    dateRange,
    EXECUTIONS_EVENT_METRICS,
    interval,
    options,
  )
}

/** Chart series + server-side top function breakdown. */
export async function fetchProjectFunctionExecutionsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectExecutionsOverview> {
  return fetchExecutionsOverviewForMetrics(
    projectId,
    dateRange,
    FUNCTION_EXECUTIONS_EVENT_METRICS,
    interval,
    options,
  )
}

/** Chart series + server-side top site breakdown. */
export async function fetchProjectSiteExecutionsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectExecutionsOverview> {
  return fetchExecutionsOverviewForMetrics(
    projectId,
    dateRange,
    SITE_EXECUTIONS_EVENT_METRICS,
    interval,
    options,
  )
}
