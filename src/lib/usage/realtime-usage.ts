import type { DateRange } from 'react-day-picker'
import {
  formatCompactBytes,
  formatCompactBytesAxis,
  formatCompactCount,
  formatCompactCountAxis,
} from '@/lib/usage/format-metric'
import {
  computeChangePercent,
  fetchProjectUsageMetricSeriesOverview,
  mergeChartPointsSeries,
  sumUsageChartPoints,
  type FetchUsageOverviewOptions,
  type UsageChartInterval,
  type UsageChartPoint,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { fetchProjectUsageGaugeChartSeries } from '@/lib/usage/usage-gauges-common'
import {
  mergeBandwidthDualChartPoints,
  type BandwidthDualChartPoint,
} from '@/lib/usage/bandwidth-events'

/**
 * Concurrent WebSocket connections - a *gauge*, read from usage.listGauges.
 * The events table holds the raw +/-1 deltas it is folded from; the events
 * endpoint refuses the metric because their sum is not a concurrency figure.
 */
export const REALTIME_CONNECTIONS_METRIC = 'realtime.connections' as const

/** Messages sent through the Realtime service (event counter). */
export const REALTIME_MESSAGES_EVENT_METRIC = 'realtime.messages.sent' as const

/** Realtime inbound bandwidth (event counter, bytes). */
export const REALTIME_INBOUND_EVENT_METRIC = 'realtime.inbound' as const

/** Realtime outbound bandwidth (event counter, bytes). */
export const REALTIME_OUTBOUND_EVENT_METRIC = 'realtime.outbound' as const

export const REALTIME_CONNECTIONS_METRICS = [
  REALTIME_CONNECTIONS_METRIC,
] as const

export const REALTIME_BANDWIDTH_EVENT_METRICS = [
  REALTIME_INBOUND_EVENT_METRIC,
  REALTIME_OUTBOUND_EVENT_METRIC,
] as const

export type RealtimeUsageChartPoint = UsageChartPoint

export interface RealtimeUsageChartOverview {
  changePercent: number
  chartPoints: RealtimeUsageChartPoint[]
}

export interface RealtimeBandwidthOverview {
  changePercent: number
  chartPoints: RealtimeUsageChartPoint[]
  inboundChartPoints: RealtimeUsageChartPoint[]
  outboundChartPoints: RealtimeUsageChartPoint[]
  dualChartPoints: BandwidthDualChartPoint[]
}

export const REALTIME_CONNECTIONS_DESCRIPTION =
  'Peak concurrent WebSocket connections during the selected period. Each open client connection counts toward your plan limit.'

export const REALTIME_MESSAGES_DESCRIPTION =
  'Messages sent through the Realtime service during the selected period. Includes server events delivered to subscribed clients.'

export const REALTIME_BANDWIDTH_DESCRIPTION =
  'Inbound and outbound data transferred through Realtime WebSocket connections during the selected period.'

export const REALTIME_DOCS_HREF = '/docs/apis/realtime'

export function formatRealtimeConnectionsTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatRealtimeConnectionsValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatRealtimeMessagesTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatRealtimeMessagesValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export {
  formatCompactBytes as formatRealtimeBandwidthTotal,
  formatCompactBytes as formatRealtimeBandwidthValue,
  formatCompactBytesAxis as formatRealtimeBandwidthAxisValue,
} from '@/lib/usage/format-metric'

export { sumUsageChartPoints } from '@/lib/usage/usage-events-common'

/** Peak value from a gauge or event time series (for concurrent connections). */
export function getUsageChartPeakValue(points: UsageChartPoint[]): number {
  if (points.length === 0) return 0
  return Math.max(...points.map((point) => point.total))
}

/**
 * Concurrent connections time series from usage.listGauges.
 *
 * Not an event metric: `realtime.connections` is emitted as +/-1 per connect
 * and disconnect, so summing it over a window gives the net change rather than
 * a concurrency figure. The server folds those deltas into a level and samples
 * it into a gauge of the same name, which `aggregate=max` reads as the peak per
 * bucket. The events endpoint refuses the metric for that reason.
 */
export async function fetchProjectRealtimeConnectionsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<RealtimeUsageChartOverview> {
  const { chartPoints, previousChartPoints } =
    await fetchProjectUsageGaugeChartSeries(
      projectId,
      dateRange,
      REALTIME_CONNECTIONS_METRICS,
      interval,
      options?.queries,
      0,
      options?.resourceId,
      options?.resourceType,
      undefined,
      'max',
    )

  return {
    changePercent: computeChangePercent(
      getUsageChartPeakValue(chartPoints),
      getUsageChartPeakValue(previousChartPoints),
    ),
    chartPoints,
  }
}

/** Messages sent event time series. */
export async function fetchProjectRealtimeMessagesOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<RealtimeUsageChartOverview> {
  const overview = await fetchProjectUsageMetricSeriesOverview(
    projectId,
    REALTIME_MESSAGES_EVENT_METRIC,
    dateRange,
    interval,
    [],
    0,
    options,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

/** Realtime inbound/outbound bandwidth time series. */
export async function fetchProjectRealtimeBandwidthOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<RealtimeBandwidthOverview> {
  const [inbound, outbound] = await Promise.all([
    fetchProjectUsageMetricSeriesOverview(
      projectId,
      REALTIME_INBOUND_EVENT_METRIC,
      dateRange,
      interval,
      [],
      0,
      options,
    ),
    fetchProjectUsageMetricSeriesOverview(
      projectId,
      REALTIME_OUTBOUND_EVENT_METRIC,
      dateRange,
      interval,
      [],
      0,
      options,
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
  }
}
