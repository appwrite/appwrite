import type { DateRange } from 'react-day-picker'
import {
  formatCompactCount,
  formatCompactCountAxis,
} from '@/lib/usage/format-metric'
import {
  fetchProjectUsageChartOverview,
  fetchProjectUsageMetricsOverview,
  type FetchUsageOverviewOptions,
  type UsageChartInterval,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'

/** Event metric for API request counts. */
export const REQUESTS_EVENT_METRICS = ['network.requests'] as const

export type RequestsChartPoint = UsageChartPoint
export type RequestsTopEndpoint = UsageTopEndpoint

export interface ProjectRequestsOverview {
  changePercent: number
  chartPoints: RequestsChartPoint[]
  topEndpoints: RequestsTopEndpoint[]
}

/** @deprecated Use ProjectRequestsOverview */
export type ProjectRequestsChartOverview = Pick<
  ProjectRequestsOverview,
  'changePercent' | 'chartPoints'
>

/** @deprecated Use ProjectRequestsOverview */
export type ProjectRequestsTopEndpointsOverview = {
  topEndpoints: RequestsTopEndpoint[]
}

export function formatRequestsTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatRequestsValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export { formatCompactCountAxis as formatRequestsAxisValue } from '@/lib/usage/format-metric'
export { sumUsageChartPoints } from '@/lib/usage/usage-events-common'

/** Chart + server-side top endpoint breakdown per metric. */
export async function fetchProjectRequestsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectRequestsOverview> {
  const overview = await fetchProjectUsageMetricsOverview(
    projectId,
    dateRange,
    REQUESTS_EVENT_METRICS,
    interval,
    undefined,
    undefined,
    options,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
    topEndpoints: overview.topEndpoints,
  }
}

/** Chart-only fetch for org project list sparklines. */
export async function fetchProjectRequestsChartOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  queries?: string[],
  logRetentionHours?: number,
): Promise<ProjectRequestsChartOverview> {
  const overview = await fetchProjectUsageChartOverview(
    projectId,
    dateRange,
    REQUESTS_EVENT_METRICS,
    interval,
    queries,
    logRetentionHours,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

/** @deprecated Use fetchProjectRequestsOverview */
export async function fetchProjectRequestsTopEndpoints(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<ProjectRequestsTopEndpointsOverview> {
  const overview = await fetchProjectRequestsOverview(
    projectId,
    dateRange,
    interval,
  )

  return { topEndpoints: overview.topEndpoints }
}
