import type { DateRange } from 'react-day-picker'
import {
  formatCompactCount,
  formatCompactCountAxis,
} from '@/lib/usage/format-metric'
import {
  fetchProjectUsageChartOverview,
  fetchProjectUsageTopEndpoints,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'

/** Event metric for API request counts. */
export const REQUESTS_EVENT_METRICS = ['network.requests'] as const

export type RequestsChartPoint = UsageChartPoint
export type RequestsTopEndpoint = UsageTopEndpoint

export interface ProjectRequestsChartOverview {
  totalRequests: number
  changePercent: number
  chartPoints: RequestsChartPoint[]
}

export type ProjectRequestsTopEndpointsOverview = {
  topEndpoints: RequestsTopEndpoint[]
}

export function formatRequestsTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatRequestsValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export { formatCompactCountAxis as formatRequestsAxisValue }

export async function fetchProjectRequestsChartOverview(
  projectId: string,
  dateRange: DateRange | undefined,
): Promise<ProjectRequestsChartOverview> {
  const overview = await fetchProjectUsageChartOverview(
    projectId,
    dateRange,
    REQUESTS_EVENT_METRICS,
  )

  return {
    totalRequests: overview.total,
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

export async function fetchProjectRequestsTopEndpoints(
  projectId: string,
  dateRange: DateRange | undefined,
): Promise<ProjectRequestsTopEndpointsOverview> {
  const overview = await fetchProjectUsageTopEndpoints(
    projectId,
    dateRange,
    REQUESTS_EVENT_METRICS,
  )

  return { topEndpoints: overview.topEndpoints }
}

/** @deprecated Use fetchProjectRequestsChartOverview + fetchProjectRequestsTopEndpoints */
export async function fetchProjectRequestsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
): Promise<
  ProjectRequestsChartOverview & ProjectRequestsTopEndpointsOverview
> {
  const [chart, top] = await Promise.all([
    fetchProjectRequestsChartOverview(projectId, dateRange),
    fetchProjectRequestsTopEndpoints(projectId, dateRange),
  ])

  return { ...chart, ...top }
}

export type ProjectRequestsOverview = ProjectRequestsChartOverview &
  ProjectRequestsTopEndpointsOverview
