import type { DateRange } from 'react-day-picker'
import {
  formatCompactBytes,
  formatCompactBytesAxis,
} from '@/lib/usage/format-metric'
import {
  computeChangePercent,
  fetchProjectUsageMetricSeriesOverview,
  mergeChartPointsSeries,
  mergeTopEndpoints,
  sumUsageChartPoints,
  type ProjectUsageChartOverview,
  type ProjectUsageTopEndpointsOverview,
  type UsageChartInterval,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'

/** Event metrics that represent project network bandwidth (in + out). */
export const BANDWIDTH_EVENT_METRICS = [
  'network.inbound',
  'network.outbound',
] as const

export type BandwidthChartPoint = UsageChartPoint
export type BandwidthTopConsumer = UsageTopEndpoint

export type BandwidthDualChartPoint = UsageChartPoint & {
  inbound: number
  outbound: number
}

export interface ProjectBandwidthOverview {
  changePercent: number
  chartPoints: BandwidthChartPoint[]
  inboundChartPoints: BandwidthChartPoint[]
  outboundChartPoints: BandwidthChartPoint[]
  dualChartPoints: BandwidthDualChartPoint[]
  topConsumers: BandwidthTopConsumer[]
}

/** @deprecated Use ProjectBandwidthOverview */
export type ProjectBandwidthChartOverview = Pick<
  ProjectBandwidthOverview,
  'changePercent' | 'chartPoints'
>

/** @deprecated Use ProjectBandwidthOverview */
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

export { computeChangePercent, sumUsageChartPoints } from '@/lib/usage/usage-events-common'

export function mergeBandwidthDualChartPoints(
  inbound: BandwidthChartPoint[],
  outbound: BandwidthChartPoint[],
): BandwidthDualChartPoint[] {
  if (inbound.length === 0) {
    return outbound.map((point) => ({
      ...point,
      inbound: 0,
      outbound: point.total,
    }))
  }

  return inbound.map((point, index) => {
    const outboundTotal = outbound[index]?.total ?? 0
    return {
      date: point.date,
      day: point.day,
      inbound: point.total,
      outbound: outboundTotal,
      total: point.total + outboundTotal,
    }
  })
}

/** One listEvents call per metric (with dimensions) for chart + breakdown. */
export async function fetchProjectBandwidthOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<ProjectBandwidthOverview> {
  const [inbound, outbound] = await Promise.all([
    fetchProjectUsageMetricSeriesOverview(
      projectId,
      'network.inbound',
      dateRange,
      interval,
    ),
    fetchProjectUsageMetricSeriesOverview(
      projectId,
      'network.outbound',
      dateRange,
      interval,
    ),
  ])

  const inboundChartPoints = inbound.chartPoints
  const outboundChartPoints = outbound.chartPoints
  const chartPoints = mergeChartPointsSeries([
    inboundChartPoints,
    outboundChartPoints,
  ])
  const previousChartPoints = mergeChartPointsSeries([
    inbound.previousChartPoints,
    outbound.previousChartPoints,
  ])

  return {
    inboundChartPoints,
    outboundChartPoints,
    dualChartPoints: mergeBandwidthDualChartPoints(
      inboundChartPoints,
      outboundChartPoints,
    ),
    chartPoints,
    changePercent: computeChangePercent(
      sumUsageChartPoints(chartPoints),
      sumUsageChartPoints(previousChartPoints),
    ),
    topConsumers: mergeTopEndpoints([
      inbound.topEndpoints,
      outbound.topEndpoints,
    ]),
  }
}

/** @deprecated Use fetchProjectBandwidthOverview */
export async function fetchProjectBandwidthChartOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<ProjectBandwidthChartOverview> {
  const overview = await fetchProjectBandwidthOverview(
    projectId,
    dateRange,
    interval,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

/** @deprecated Use fetchProjectBandwidthOverview */
export async function fetchProjectBandwidthTopConsumers(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<ProjectBandwidthTopConsumersOverview> {
  const overview = await fetchProjectBandwidthOverview(
    projectId,
    dateRange,
    interval,
  )

  return { topConsumers: overview.topConsumers }
}

export type {
  ProjectUsageChartOverview,
  ProjectUsageTopEndpointsOverview,
}
