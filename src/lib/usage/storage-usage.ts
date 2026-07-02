import type { DateRange } from 'react-day-picker'
import {
  formatCompactBytes,
  formatCompactBytesAxis,
  formatCompactCount,
  formatCompactCountAxis,
} from '@/lib/usage/format-metric'
import {
  type FetchUsageOverviewOptions,
  type UsageBreakdownItem,
  type UsageChartInterval,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'
import {
  fetchProjectUsageGaugeChartSeries,
  fetchProjectUsageGaugeSnapshotOverview,
  fetchProjectUsageGaugesChartOverview,
  fetchUsageGaugeBreakdown,
} from '@/lib/usage/usage-gauges-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { getUsageChartLatestValue } from '@/lib/usage/database-usage'
import { areUsageBreakdownQueriesEnabled } from '@/lib/debug-overrides'
import {
  computeChangePercent,
  resolveOverviewUsagePeriod,
} from '@/lib/usage/usage-events-common'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'

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
  resourceTypeBreakdown: UsageBreakdownItem[]
}

export interface StorageImageTransformationsOverview
  extends StorageUsageChartOverview {
  topConsumers: StorageTopConsumer[]
  resourceTypeBreakdown: UsageBreakdownItem[]
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

export const STORAGE_RESOURCE_TYPES_BREAKDOWN_TITLE = 'Resource types'
export const STORAGE_FILE_BREAKDOWN_TITLE = 'Resource IDs'
export const STORAGE_DEPLOYMENTS_BREAKDOWN_TITLE = 'Resource IDs'
export const STORAGE_BUILDS_BREAKDOWN_TITLE = 'Resource IDs'
export const IMAGE_TRANSFORMATIONS_BREAKDOWN_TITLE = 'Resource IDs'
export const OVERVIEW_STORAGE_CHART_TITLE = 'Storage over time'

export type OverviewStorageBreakdownType = 'files' | 'deployments' | 'builds'

export const OVERVIEW_STORAGE_BREAKDOWN_OPTIONS: ReadonlyArray<{
  value: OverviewStorageBreakdownType
  label: string
  title: string
}> = [
  {
    value: 'files',
    label: 'Buckets',
    title: STORAGE_FILE_BREAKDOWN_TITLE,
  },
  {
    value: 'deployments',
    label: 'Deployments',
    title: STORAGE_DEPLOYMENTS_BREAKDOWN_TITLE,
  },
  {
    value: 'builds',
    label: 'Builds',
    title: STORAGE_BUILDS_BREAKDOWN_TITLE,
  },
] as const

export type OverviewStorageBreakdown = Record<
  OverviewStorageBreakdownType,
  StorageTopConsumer[]
>

export const OVERVIEW_STORAGE_GAUGE_METRICS = [
  BUCKET_FILE_STORAGE_GAUGE_METRIC,
  DEPLOYMENTS_STORAGE_GAUGE_METRIC,
  BUILDS_STORAGE_GAUGE_METRIC,
] as const

export type OverviewStorageChartPoint = UsageChartPoint & {
  files: number
  deployments: number
  builds: number
}

export interface ProjectOverviewStorageOverview {
  changePercent: number
  latestValue: number
  latestFiles: number
  latestDeployments: number
  latestBuilds: number
  chartPoints: OverviewStorageChartPoint[]
  /** @deprecated Use `storageBreakdown.files`. */
  topConsumers: StorageTopConsumer[]
  storageBreakdown: OverviewStorageBreakdown
}

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
  queries?: string[],
): Promise<StorageUsageChartOverview> {
  const overview = await fetchProjectUsageGaugesChartOverview(
    projectId,
    dateRange,
    metrics,
    interval,
    queries,
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
  const queries = options?.queries
  const { from, to } = resolveOverviewUsagePeriod(dateRange, interval)

  const [chartOverview, snapshotOverview, resourceTypeConsumers] =
    await Promise.all([
      fetchProjectUsageGaugesChartOverview(
        projectId,
        dateRange,
        [metric],
        interval,
        queries,
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
      includeBreakdown
        ? fetchUsageGaugeBreakdown(
            projectId,
            metric,
            from,
            to,
            ['resource'],
            OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
            queries,
          )
        : Promise.resolve([]),
    ])

  return {
    changePercent: chartOverview.changePercent,
    chartPoints: chartOverview.chartPoints,
    topConsumers: snapshotOverview.topConsumers,
    resourceTypeBreakdown: resourceTypeConsumers.map((item) => ({
      id: item.id,
      label: item.path,
      count: item.count,
    })),
  }
}

/** Latest bucket file storage snapshot + top bucket breakdown (legacy overview hook). */
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

function mergeOverviewStorageChartPoints(
  files: UsageChartPoint[],
  deployments: UsageChartPoint[],
  builds: UsageChartPoint[],
): OverviewStorageChartPoint[] {
  const byTime = new Map<number, OverviewStorageChartPoint>()

  const applySeries = (
    points: UsageChartPoint[],
    key: 'files' | 'deployments' | 'builds',
  ) => {
    for (const point of points) {
      const timeMs = point.day.getTime()
      const existing = byTime.get(timeMs)
      if (existing) {
        existing[key] = point.total
      } else {
        byTime.set(timeMs, {
          date: point.date,
          day: point.day,
          files: key === 'files' ? point.total : 0,
          deployments: key === 'deployments' ? point.total : 0,
          builds: key === 'builds' ? point.total : 0,
          total: 0,
        })
      }
    }
  }

  applySeries(files, 'files')
  applySeries(deployments, 'deployments')
  applySeries(builds, 'builds')

  return Array.from(byTime.values())
    .map((point) => ({
      ...point,
      total: point.files + point.deployments + point.builds,
    }))
    .sort((a, b) => a.day.getTime() - b.day.getTime())
}

function getLatestOverviewStorageComponents(
  chartPoints: OverviewStorageChartPoint[],
): Pick<
  ProjectOverviewStorageOverview,
  'latestValue' | 'latestFiles' | 'latestDeployments' | 'latestBuilds'
> {
  const latest = chartPoints.at(-1)
  return {
    latestValue: latest?.total ?? 0,
    latestFiles: latest?.files ?? 0,
    latestDeployments: latest?.deployments ?? 0,
    latestBuilds: latest?.builds ?? 0,
  }
}

/** Stacked file, deployment, and build storage chart + bucket breakdown for overview. */
export async function fetchProjectOverviewStorageOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectOverviewStorageOverview> {
  if (!projectId) {
    return {
      changePercent: 0,
      latestValue: 0,
      latestFiles: 0,
      latestDeployments: 0,
      latestBuilds: 0,
      chartPoints: [],
      topConsumers: [],
      storageBreakdown: {
        files: [],
        deployments: [],
        builds: [],
      },
    }
  }

  const includeBreakdown =
    options?.includeBreakdown !== false && areUsageBreakdownQueriesEnabled()
  const queries = options?.queries

  const { from, to } = resolveOverviewUsagePeriod(dateRange, interval)

  const [
    filesSeries,
    deploymentsSeries,
    buildsSeries,
    filesBreakdown,
    deploymentsBreakdown,
    buildsBreakdown,
  ] = await Promise.all([
    fetchProjectUsageGaugeChartSeries(
      projectId,
      dateRange,
      [BUCKET_FILE_STORAGE_GAUGE_METRIC],
      interval,
      queries,
    ),
    fetchProjectUsageGaugeChartSeries(
      projectId,
      dateRange,
      [DEPLOYMENTS_STORAGE_GAUGE_METRIC],
      interval,
      queries,
    ),
    fetchProjectUsageGaugeChartSeries(
      projectId,
      dateRange,
      [BUILDS_STORAGE_GAUGE_METRIC],
      interval,
      queries,
    ),
    includeBreakdown
      ? fetchUsageGaugeBreakdown(
          projectId,
          BUCKET_FILE_STORAGE_GAUGE_METRIC,
          from,
          to,
          ['resourceId'],
          OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
          queries,
        )
      : Promise.resolve([]),
    includeBreakdown
      ? fetchUsageGaugeBreakdown(
          projectId,
          DEPLOYMENTS_STORAGE_GAUGE_METRIC,
          from,
          to,
          ['resourceId'],
          OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
          queries,
        )
      : Promise.resolve([]),
    includeBreakdown
      ? fetchUsageGaugeBreakdown(
          projectId,
          BUILDS_STORAGE_GAUGE_METRIC,
          from,
          to,
          ['resourceId'],
          OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
          queries,
        )
      : Promise.resolve([]),
  ])

  const chartPoints = mergeOverviewStorageChartPoints(
    filesSeries.chartPoints,
    deploymentsSeries.chartPoints,
    buildsSeries.chartPoints,
  )
  const previousChartPoints = mergeOverviewStorageChartPoints(
    filesSeries.previousChartPoints,
    deploymentsSeries.previousChartPoints,
    buildsSeries.previousChartPoints,
  )

  const latest = getLatestOverviewStorageComponents(chartPoints)
  const previousLatest = getLatestOverviewStorageComponents(previousChartPoints)

  const storageBreakdown: OverviewStorageBreakdown = {
    files: filesBreakdown,
    deployments: deploymentsBreakdown,
    builds: buildsBreakdown,
  }

  return {
    ...latest,
    changePercent: computeChangePercent(
      latest.latestValue,
      previousLatest.latestValue,
    ),
    chartPoints,
    topConsumers: filesBreakdown,
    storageBreakdown,
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
  options?: FetchUsageOverviewOptions,
): Promise<StorageUsageChartOverview> {
  return fetchStorageGaugeChartOverview(
    projectId,
    dateRange,
    STORAGE_DEPLOYMENTS_GAUGE_METRICS,
    interval,
    options?.queries,
  )
}

/** Build artifact storage time series. */
export async function fetchProjectStorageBuildsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<StorageUsageChartOverview> {
  return fetchStorageGaugeChartOverview(
    projectId,
    dateRange,
    STORAGE_BUILDS_GAUGE_METRICS,
    interval,
    options?.queries,
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
