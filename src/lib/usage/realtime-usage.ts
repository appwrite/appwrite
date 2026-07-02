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
import {
  mergeBandwidthDualChartPoints,
  type BandwidthDualChartPoint,
} from '@/lib/usage/bandwidth-events'

/** Peak concurrent WebSocket connections (event metric on usage.listEvents). */
export const REALTIME_CONNECTIONS_EVENT_METRIC = 'realtime.connections' as const

/** Messages sent through the Realtime service (event counter). */
export const REALTIME_MESSAGES_EVENT_METRIC = 'realtime.messages.sent' as const

/** Realtime inbound bandwidth (event counter, bytes). */
export const REALTIME_INBOUND_EVENT_METRIC = 'realtime.inbound' as const

/** Realtime outbound bandwidth (event counter, bytes). */
export const REALTIME_OUTBOUND_EVENT_METRIC = 'realtime.outbound' as const

export const REALTIME_CONNECTIONS_EVENT_METRICS = [
  REALTIME_CONNECTIONS_EVENT_METRIC,
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

/** Concurrent connections time series from usage.listEvents. */
export async function fetchProjectRealtimeConnectionsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<RealtimeUsageChartOverview> {
  const overview = await fetchProjectUsageMetricSeriesOverview(
    projectId,
    REALTIME_CONNECTIONS_EVENT_METRIC,
    dateRange,
    interval,
    [],
    0,
    options,
  )

  return {
    changePercent: computeChangePercent(
      getUsageChartPeakValue(overview.chartPoints),
      getUsageChartPeakValue(overview.previousChartPoints),
    ),
    chartPoints: overview.chartPoints,
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
