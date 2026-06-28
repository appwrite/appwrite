import type { DateRange } from 'react-day-picker'
import {
  formatCompactBytes,
  formatCompactBytesAxis,
  formatCompactCount,
  formatCompactCountAxis,
} from '@/lib/usage/format-metric'
import {
  type FetchUsageOverviewOptions,
  type UsageChartInterval,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'
import {
  fetchProjectUsageGaugeSnapshotOverview,
  fetchProjectUsageGaugesChartOverview,
} from '@/lib/usage/usage-gauges-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { getUsageChartLatestValue } from '@/lib/usage/database-usage'
import { areUsageBreakdownQueriesEnabled } from '@/lib/debug-overrides'

/** Bytes stored across all storage buckets. */
export const BUCKET_FILE_STORAGE_GAUGE_METRIC = 'files.storage' as const

/** Bytes used by active function and site deployment artifacts. */
export const DEPLOYMENTS_STORAGE_GAUGE_METRIC = 'deployments.storage' as const

/** Bytes used by function and site build artifacts and caches. */
export const BUILDS_STORAGE_GAUGE_METRIC = 'builds.storage' as const

/** Unique origin images transformed (billable image transformation usage). */
export const IMAGE_TRANSFORMATIONS_GAUGE_METRIC =
  'files.imagesTransformed' as const

export const STORAGE_FILE_GAUGE_METRICS = [
  BUCKET_FILE_STORAGE_GAUGE_METRIC,
] as const

export const STORAGE_DEPLOYMENTS_GAUGE_METRICS = [
  DEPLOYMENTS_STORAGE_GAUGE_METRIC,
] as const

export const STORAGE_BUILDS_GAUGE_METRICS = [BUILDS_STORAGE_GAUGE_METRIC] as const

export const IMAGE_TRANSFORMATIONS_GAUGE_METRICS = [
  IMAGE_TRANSFORMATIONS_GAUGE_METRIC,
] as const

export type StorageUsageChartPoint = UsageChartPoint
export type StorageTopConsumer = UsageTopEndpoint

export interface StorageUsageChartOverview {
  changePercent: number
  chartPoints: StorageUsageChartPoint[]
}

export interface StorageFilesUsageOverview extends StorageUsageChartOverview {
  topConsumers: StorageTopConsumer[]
}

export interface StorageImageTransformationsOverview
  extends StorageUsageChartOverview {
  topConsumers: StorageTopConsumer[]
}

export const STORAGE_FILE_DESCRIPTION =
  'Total bytes stored across all buckets, including uploaded files and versions. Counts toward your plan storage limit.'

export const STORAGE_DEPLOYMENTS_DESCRIPTION =
  'Storage used by active function and site deployment artifacts. Deployment files count toward your plan storage limit.'

export const STORAGE_BUILDS_DESCRIPTION =
  'Storage used by function and site build artifacts and caches. Build files count toward your plan storage limit.'

export const IMAGE_TRANSFORMATIONS_DESCRIPTION =
  'Unique origin images transformed during the selected period. Each origin image is billed once, regardless of how many variants you generate from it.'

export const STORAGE_DOCS_HREF = '/docs/products/storage'
export const IMAGE_TRANSFORMATIONS_DOCS_HREF =
  '/docs/advanced/platform/image-transformations'

export const STORAGE_FILE_BREAKDOWN_TITLE = 'Top storage buckets'
export const IMAGE_TRANSFORMATIONS_BREAKDOWN_TITLE =
  'Top buckets by origin images'

export function formatStorageBytesTotal(bytes: number): string {
  return formatCompactBytes(bytes, { compact: true })
}

export function formatStorageBytesValue(bytes: number): string {
  return formatCompactBytes(bytes, { compact: true })
}

export function formatImageTransformationsTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatImageTransformationsValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export {
  formatCompactBytesAxis as formatStorageBytesAxisValue,
  formatCompactCountAxis as formatImageTransformationsAxisValue,
} from '@/lib/usage/format-metric'

export { sumUsageChartPoints } from '@/lib/usage/usage-events-common'
export { getUsageChartLatestValue as getStorageGaugeDisplayTotal } from '@/lib/usage/database-usage'

async function fetchStorageGaugeChartOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<StorageUsageChartOverview> {
  const overview = await fetchProjectUsageGaugesChartOverview(
    projectId,
    dateRange,
    metrics,
    interval,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

async function fetchStorageGaugeUsageOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  metric: string,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<StorageFilesUsageOverview> {
  const includeBreakdown =
    options?.includeBreakdown !== false && areUsageBreakdownQueriesEnabled()

  const [chartOverview, snapshotOverview] = await Promise.all([
    fetchProjectUsageGaugesChartOverview(
      projectId,
      dateRange,
      [metric],
      interval,
    ),
    includeBreakdown
      ? fetchProjectUsageGaugeSnapshotOverview(
          projectId,
          dateRange,
          metric,
          interval,
          { dimensions: ['resourceId'] },
          options,
        )
      : Promise.resolve({
          changePercent: 0,
          latestValue: 0,
          topConsumers: [],
        }),
  ])

  return {
    changePercent: chartOverview.changePercent,
    chartPoints: chartOverview.chartPoints,
    topConsumers: snapshotOverview.topConsumers,
  }
}

/** Latest bucket file storage snapshot + top bucket breakdown (overview tab). */
export async function fetchProjectStorageOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<{
  changePercent: number
  latestValue: number
  topConsumers: StorageTopConsumer[]
}> {
  const overview = await fetchProjectUsageGaugeSnapshotOverview(
    projectId,
    dateRange,
    BUCKET_FILE_STORAGE_GAUGE_METRIC,
    interval,
    {
      dimensions: ['resourceId'],
    },
    options,
  )

  return {
    changePercent: overview.changePercent,
    latestValue: overview.latestValue,
    topConsumers: overview.topConsumers,
  }
}

/** Bucket file storage time series + top bucket breakdown. */
export async function fetchProjectStorageFilesUsageOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<StorageFilesUsageOverview> {
  return fetchStorageGaugeUsageOverview(
    projectId,
    dateRange,
    BUCKET_FILE_STORAGE_GAUGE_METRIC,
    interval,
    options,
  )
}

/** Deployment artifact storage time series. */
export async function fetchProjectStorageDeploymentsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<StorageUsageChartOverview> {
  return fetchStorageGaugeChartOverview(
    projectId,
    dateRange,
    STORAGE_DEPLOYMENTS_GAUGE_METRICS,
    interval,
  )
}

/** Build artifact storage time series. */
export async function fetchProjectStorageBuildsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<StorageUsageChartOverview> {
  return fetchStorageGaugeChartOverview(
    projectId,
    dateRange,
    STORAGE_BUILDS_GAUGE_METRICS,
    interval,
  )
}

/** Origin image transformations time series + top bucket breakdown. */
export async function fetchProjectImageTransformationsUsageOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<StorageImageTransformationsOverview> {
  return fetchStorageGaugeUsageOverview(
    projectId,
    dateRange,
    IMAGE_TRANSFORMATIONS_GAUGE_METRIC,
    interval,
    options,
  )
}
