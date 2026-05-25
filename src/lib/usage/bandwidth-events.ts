import type { DateRange } from 'react-day-picker'
import {
  formatCompactBytes,
  formatCompactBytesAxis,
} from '@/lib/usage/format-metric'
import {
  computeChangePercent,
  fetchProjectUsageChartOverview,
  fetchProjectUsageTopEndpoints,
  type ProjectUsageChartOverview,
  type ProjectUsageTopEndpointsOverview,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'

/** Event metrics that represent project network bandwidth (in + out). */
export const BANDWIDTH_EVENT_METRICS = [
  'network.inbound',
  'network.outbound',
] as const

export type BandwidthChartPoint = UsageChartPoint
export type BandwidthTopConsumer = UsageTopEndpoint

export interface ProjectBandwidthChartOverview {
  totalBytes: number
  changePercent: number
  chartPoints: BandwidthChartPoint[]
}

export type ProjectBandwidthTopConsumersOverview = {
  topConsumers: BandwidthTopConsumer[]
}

export function formatBandwidthTotal(bytes: number): string {
  return formatCompactBytes(bytes, { compact: true })
}

export function formatBandwidthValue(bytes: number): string {
  return formatCompactBytes(bytes, { compact: true })
}

export { formatCompactBytesAxis as formatBandwidthAxisValue }

export { computeChangePercent }

export async function fetchProjectBandwidthChartOverview(
  projectId: string,
  dateRange: DateRange | undefined,
): Promise<ProjectBandwidthChartOverview> {
  const overview = await fetchProjectUsageChartOverview(
    projectId,
    dateRange,
    BANDWIDTH_EVENT_METRICS,
  )

  return {
    totalBytes: overview.total,
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

export async function fetchProjectBandwidthTopConsumers(
  projectId: string,
  dateRange: DateRange | undefined,
): Promise<ProjectBandwidthTopConsumersOverview> {
  const overview = await fetchProjectUsageTopEndpoints(
    projectId,
    dateRange,
    BANDWIDTH_EVENT_METRICS,
  )

  return { topConsumers: overview.topEndpoints }
}

/** @deprecated Use fetchProjectBandwidthChartOverview + fetchProjectBandwidthTopConsumers */
export async function fetchProjectBandwidthOverview(
  projectId: string,
  dateRange: DateRange | undefined,
): Promise<
  ProjectBandwidthChartOverview & ProjectBandwidthTopConsumersOverview
> {
  const [chart, top] = await Promise.all([
    fetchProjectBandwidthChartOverview(projectId, dateRange),
    fetchProjectBandwidthTopConsumers(projectId, dateRange),
  ])

  return { ...chart, ...top }
}

export type ProjectBandwidthOverview = ProjectBandwidthChartOverview &
  ProjectBandwidthTopConsumersOverview

export type {
  ProjectUsageChartOverview,
  ProjectUsageTopEndpointsOverview,
}
