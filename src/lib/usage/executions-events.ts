import type { DateRange } from 'react-day-picker'
import {
  formatCompactCount,
  formatCompactCountAxis,
} from '@/lib/usage/format-metric'
import {
  fetchProjectUsageMetricsOverview,
  type UsageChartInterval,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { COMPUTE_BREAKDOWN_RESOURCE_LIMIT } from '@/lib/usage/breakdown-limits'

/** Event metric for function and site execution counts. */
export const EXECUTIONS_EVENT_METRICS = ['executions'] as const

const EXECUTIONS_BREAKDOWN_DIMENSIONS = ['resourceId'] as const

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

/** Chart series + server-side top resource breakdown. */
export async function fetchProjectExecutionsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<ProjectExecutionsOverview> {
  const overview = await fetchProjectUsageMetricsOverview(
    projectId,
    dateRange,
    EXECUTIONS_EVENT_METRICS,
    interval,
    EXECUTIONS_BREAKDOWN_DIMENSIONS,
    COMPUTE_BREAKDOWN_RESOURCE_LIMIT,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
    topConsumers: overview.topEndpoints,
  }
}
