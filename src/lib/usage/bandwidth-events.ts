import type { DateRange } from 'react-day-picker'
import {
  formatCompactBytes,
  formatCompactBytesAxis,
} from '@/lib/usage/format-metric'
import { areUsageBreakdownQueriesEnabled } from '@/lib/debug-overrides'
import {
  computeChangePercent,
  fetchUsageMetricsChartSeriesByMetric,
  fetchUsageMetricsBreakdownByMetric,
  mergeChartPointsSeries,
  mergeTopEndpoints,
  sumUsageChartPoints,
  type FetchUsageOverviewOptions,
  type ProjectUsageChartOverview,
  type ProjectUsageTopEndpointsOverview,
  type UsageChartInterval,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { DEFAULT_USAGE_LOG_RETENTION_HOURS } from '@/lib/usage/usage-log-retention'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'

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

type BandwidthDualChartDisplayPoint = {
  inbound?: number
  outbound?: number
  total?: number
}

/** Stacked dual series: scale Y-axis to combined inbound + outbound height. */
export function resolveBandwidthDualChartDisplay(
  points: readonly BandwidthDualChartDisplayPoint[],
): { showDualSeries: boolean; axisMax: number } {
  const hasDualFields = points.some(
    (point) =>
      typeof point.inbound === 'number' && typeof point.outbound === 'number',
  )
  const stackedMax = hasDualFields
    ? points.reduce(
        (max, point) =>
          Math.max(max, (point.inbound ?? 0) + (point.outbound ?? 0)),
        0,
      )
    : 0
  const showDualSeries = hasDualFields && stackedMax > 0
  const axisMax = showDualSeries
    ? stackedMax
    : points.reduce((max, point) => Math.max(max, point.total ?? 0), 0)

  return { showDualSeries, axisMax }
}

/** Y-axis domain for stacked inbound + outbound - pins scale to combined peak. */
export function resolveBandwidthStackedYAxisDomain(
  axisMax: number,
): [number, number] | undefined {
  if (!Number.isFinite(axisMax) || axisMax <= 0) return undefined
  return [0, axisMax]
}

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

/** Inbound/outbound chart series + server-side top endpoint breakdown. */
export async function fetchProjectBandwidthOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectBandwidthOverview> {
  const includeBreakdown =
    options?.includeBreakdown !== false && areUsageBreakdownQueriesEnabled()
  const queries = options?.queries
  const logRetentionHours =
    options?.logRetentionHours ?? DEFAULT_USAGE_LOG_RETENTION_HOURS

  const [chartSeriesByMetric, breakdownByMetric] = await Promise.all([
    fetchUsageMetricsChartSeriesByMetric(
      projectId,
      BANDWIDTH_EVENT_METRICS,
      dateRange,
      interval,
      queries,
      logRetentionHours,
    ),
    includeBreakdown
      ? fetchUsageMetricsBreakdownByMetric(
          projectId,
          BANDWIDTH_EVENT_METRICS,
          dateRange,
          ['path'],
          OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
          queries,
        )
      : Promise.resolve(new Map<string, UsageTopEndpoint[]>()),
  ])

  const inbound = chartSeriesByMetric.get('network.inbound') ?? {
    chartPoints: [],
    previousChartPoints: [],
  }
  const outbound = chartSeriesByMetric.get('network.outbound') ?? {
    chartPoints: [],
    previousChartPoints: [],
  }

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
    topConsumers: mergeTopEndpoints(
      [
        breakdownByMetric.get('network.inbound') ?? [],
        breakdownByMetric.get('network.outbound') ?? [],
      ],
      OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
    ),
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
