import type { DateRange } from 'react-day-picker'
import {
  fetchProjectUsageEventBreakdown,
  fetchProjectUsageMetricsOverview,
  type FetchUsageOverviewOptions,
  type UsageBreakdownItem,
  type UsageChartInterval,
  type UsageChartPoint,
  type UsageEventBreakdownDimension,
} from '@/lib/usage/usage-events-common'
import { fetchProjectUsageGaugesChartOverview } from '@/lib/usage/usage-gauges-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { DEFAULT_USAGE_LOG_RETENTION_HOURS } from '@/lib/usage/usage-log-retention'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import { buildUsageResourceFilterQueries } from '@/lib/usage/usage-resource-queries'
import { VIDEO_USAGE_SERVICE } from '@/lib/usage/gb-hours-events'

/**
 * Videos API traffic. Every `/v1/videos/*` request is tagged `service=videos`
 * and, for routes under one video, `resourceType=video` + `resourceId`, so
 * playback (manifests, segments, subtitles, previews) breaks down per video.
 */
export const VIDEOS_BANDWIDTH_EVENT_METRIC = 'network.outbound' as const
export const VIDEOS_REQUESTS_EVENT_METRIC = 'network.requests' as const

/**
 * Settled rendition encodes, published by the videos worker. They carry
 * `resourceType=video` but no `resourceId`, so they have no per-video split.
 */
export const RENDITIONS_SUCCESS_EVENT_METRIC = 'renditions.success' as const
export const RENDITIONS_FAILED_EVENT_METRIC = 'renditions.failed' as const

/** Videos in the project (gauge snapshot). */
export const VIDEOS_GAUGE_METRIC = 'videos' as const

export type VideosTrafficEventMetric =
  | typeof VIDEOS_BANDWIDTH_EVENT_METRIC
  | typeof VIDEOS_REQUESTS_EVENT_METRIC

export type VideosRenditionsEventMetric =
  | typeof RENDITIONS_SUCCESS_EVENT_METRIC
  | typeof RENDITIONS_FAILED_EVENT_METRIC

/** Breakdowns shown under each traffic chart, in display order. */
export const VIDEOS_TRAFFIC_BREAKDOWN_DIMENSIONS = [
  'resource',
  'country',
  'path',
] as const satisfies readonly UsageEventBreakdownDimension[]

export type VideosTrafficBreakdownDimension =
  (typeof VIDEOS_TRAFFIC_BREAKDOWN_DIMENSIONS)[number]

export const VIDEOS_BREAKDOWN_TITLES: Record<
  VideosTrafficBreakdownDimension,
  string
> = {
  resource: 'Videos',
  country: 'Countries',
  path: 'Endpoints',
}

export const VIDEOS_BANDWIDTH_DESCRIPTION =
  'Bandwidth served by the Videos API during the selected period, including manifests, segments, subtitles, and previews.'

export const VIDEOS_REQUESTS_DESCRIPTION =
  'Requests to the Videos API during the selected period. Players fetch a manifest and then one request per segment.'

export const RENDITIONS_SUCCESS_DESCRIPTION =
  'Renditions encoded and packaged during the selected period. Each rendition is one profile encoded for one output.'

export const RENDITIONS_FAILED_DESCRIPTION =
  'Renditions that failed or were aborted during the selected period.'

export const VIDEOS_COUNT_DESCRIPTION = 'Videos in your project.'

export const VIDEOS_DOCS_HREF = '/docs/products/videos'

export interface VideosUsageChartOverview {
  changePercent: number
  chartPoints: UsageChartPoint[]
}

function videosServiceQueries(queries?: string[]): string[] | undefined {
  return buildUsageResourceFilterQueries({
    queries,
    service: VIDEO_USAGE_SERVICE,
  })
}

/** Videos API bandwidth or requests over time. */
export async function fetchProjectVideosTrafficOverview(
  projectId: string,
  metric: VideosTrafficEventMetric,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<VideosUsageChartOverview> {
  const overview = await fetchProjectUsageMetricsOverview(
    projectId,
    dateRange,
    [metric],
    interval,
    [],
    0,
    {
      ...options,
      includeBreakdown: false,
      queries: videosServiceQueries(options?.queries),
    },
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

/** Top-N Videos API bandwidth or requests for one dimension. */
export async function fetchProjectVideosTrafficBreakdown(
  projectId: string,
  metric: VideosTrafficEventMetric,
  dateRange: DateRange | undefined,
  dimension: VideosTrafficBreakdownDimension,
  queries?: string[],
  logRetentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
): Promise<UsageBreakdownItem[]> {
  const items = await fetchProjectUsageEventBreakdown(
    projectId,
    metric,
    dateRange,
    dimension,
    OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
    videosServiceQueries(queries),
    undefined,
    undefined,
    logRetentionHours,
  )

  // Routes outside one video (list, create) have no resource; keep the
  // per-video list to videos.
  return dimension === 'resource'
    ? items.filter((item) => item.resourceType === 'video')
    : items
}

/** Encoded or failed renditions over time. */
export async function fetchProjectRenditionsOverview(
  projectId: string,
  metric: VideosRenditionsEventMetric,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<VideosUsageChartOverview> {
  const overview = await fetchProjectUsageMetricsOverview(
    projectId,
    dateRange,
    [metric],
    interval,
    [],
    0,
    { ...options, includeBreakdown: false },
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

/** Videos in the project over time (gauge). */
export async function fetchProjectVideosCountOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<VideosUsageChartOverview> {
  const overview = await fetchProjectUsageGaugesChartOverview(
    projectId,
    dateRange,
    [VIDEOS_GAUGE_METRIC],
    interval,
    options?.queries,
    options?.logRetentionHours ?? DEFAULT_USAGE_LOG_RETENTION_HOURS,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}
