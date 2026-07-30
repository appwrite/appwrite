import { Query } from '@appwrite.io/console'
import type { DateRange } from 'react-day-picker'
import {
  formatCompactBytes,
  formatCompactCount,
} from '@/lib/usage/format-metric'
import {
  type FetchUsageOverviewOptions,
  type UsageChartInterval,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'
import {
  fetchProjectUsageGaugeChartSeriesByResourceType,
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

/**
 * Unified storage gauge. One row per resource instance, tagged with the owning
 * `resourceType` - it replaces the per-service `files.storage`,
 * `deployments.storage` and `builds.storage` gauges. Deployment and build bytes
 * are folded into their owning function/site row, so they are no longer
 * separable from this metric.
 */
export const STORAGE_GAUGE_METRIC = 'storage' as const

/** Unique origin images transformed (billable image transformation usage). */
export const IMAGE_TRANSFORMATIONS_GAUGE_METRIC =
  'files.imagesTransformed' as const

export const IMAGE_TRANSFORMATIONS_GAUGE_METRICS = [
  IMAGE_TRANSFORMATIONS_GAUGE_METRIC,
] as const

export type StorageUsageChartPoint = UsageChartPoint
export type StorageTopConsumer = UsageTopEndpoint

export interface StorageUsageChartOverview {
  changePercent: number
  chartPoints: StorageUsageChartPoint[]
}

export interface StorageResourceTypeUsageOverview
  extends StorageUsageChartOverview {
  topConsumers: StorageTopConsumer[]
}

export interface StorageImageTransformationsOverview
  extends StorageUsageChartOverview {
  topConsumers: StorageTopConsumer[]
}

export const IMAGE_TRANSFORMATIONS_DESCRIPTION =
  'Unique origin images transformed during the selected period. Each origin image is billed once, regardless of how many variants you generate from it.'

export const STORAGE_DOCS_HREF = '/docs/products/storage'
export const IMAGE_TRANSFORMATIONS_DOCS_HREF =
  '/docs/advanced/platform/image-transformations'

export const IMAGE_TRANSFORMATIONS_BREAKDOWN_TITLE = 'Resource IDs'
export const OVERVIEW_STORAGE_CHART_TITLE = 'Storage over time'

/** One panel per resource family the unified storage gauge reports. */
export type OverviewStorageBreakdownType =
  | 'buckets'
  | 'databases'
  | 'functions'
  | 'sites'

export interface OverviewStorageBreakdownOption {
  value: OverviewStorageBreakdownType
  label: string
  title: string
  /** `resourceType` tag values that roll up into this panel. */
  resourceTypes: readonly string[]
  description: string
}

export const OVERVIEW_STORAGE_BREAKDOWN_OPTIONS: ReadonlyArray<OverviewStorageBreakdownOption> =
  [
    {
      value: 'buckets',
      label: 'Buckets',
      title: 'Buckets',
      resourceTypes: ['bucket'],
      description:
        'Bytes stored across all storage buckets, including uploaded files and versions. Counts toward your plan storage limit.',
    },
    {
      value: 'databases',
      label: 'Databases',
      title: 'Databases',
      // Dedicated database instances report under their own resource type but
      // are still database storage.
      resourceTypes: ['database', 'dedicatedDatabases'],
      description:
        'Bytes stored by your databases, including dedicated database instances. Counts toward your plan storage limit.',
    },
    {
      value: 'functions',
      label: 'Functions',
      title: 'Functions',
      resourceTypes: ['function'],
      description:
        'Storage used by function deployment artifacts and their builds. Counts toward your plan storage limit.',
    },
    {
      value: 'sites',
      label: 'Sites',
      title: 'Sites',
      resourceTypes: ['site'],
      description:
        'Storage used by site deployment artifacts and their builds. Counts toward your plan storage limit.',
    },
  ] as const

export const OVERVIEW_STORAGE_BREAKDOWN_TYPES =
  OVERVIEW_STORAGE_BREAKDOWN_OPTIONS.map(
    (option) => option.value,
  ) as readonly OverviewStorageBreakdownType[]

export function getOverviewStorageBreakdownOption(
  value: OverviewStorageBreakdownType,
): OverviewStorageBreakdownOption {
  return (
    OVERVIEW_STORAGE_BREAKDOWN_OPTIONS.find(
      (option) => option.value === value,
    ) ?? OVERVIEW_STORAGE_BREAKDOWN_OPTIONS[0]
  )
}

/** Utopia `queries[]` scoping the unified gauge to one panel's resource types. */
function storageResourceTypeQueries(
  breakdownType: OverviewStorageBreakdownType,
  queries?: string[],
): string[] {
  return [
    ...(queries ?? []),
    Query.equal('resourceType', [
      ...getOverviewStorageBreakdownOption(breakdownType).resourceTypes,
    ]),
  ]
}

export type OverviewStorageBreakdown = Record<
  OverviewStorageBreakdownType,
  StorageTopConsumer[]
>

export type OverviewStorageChartPoint = UsageChartPoint &
  Record<OverviewStorageBreakdownType, number>

export interface ProjectOverviewStorageOverview {
  changePercent: number
  latestValue: number
  latestByType: Record<OverviewStorageBreakdownType, number>
  chartPoints: OverviewStorageChartPoint[]
  storageBreakdown: OverviewStorageBreakdown
}

function emptyStorageBreakdown(): OverviewStorageBreakdown {
  return {
    buckets: [],
    databases: [],
    functions: [],
    sites: [],
  }
}

function emptyStorageTotals(): Record<OverviewStorageBreakdownType, number> {
  return { buckets: 0, databases: 0, functions: 0, sites: 0 }
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

function mergeOverviewStorageChartPoints(
  pointsByType: Map<string, UsageChartPoint[]>,
): OverviewStorageChartPoint[] {
  const byTime = new Map<number, OverviewStorageChartPoint>()

  for (const type of OVERVIEW_STORAGE_BREAKDOWN_TYPES) {
    for (const point of pointsByType.get(type) ?? []) {
      const timeMs = point.day.getTime()
      const existing = byTime.get(timeMs)
      if (existing) {
        existing[type] = point.total
      } else {
        byTime.set(timeMs, {
          date: point.date,
          day: point.day,
          ...emptyStorageTotals(),
          [type]: point.total,
          total: 0,
        })
      }
    }
  }

  return Array.from(byTime.values())
    .map((point) => ({
      ...point,
      total: OVERVIEW_STORAGE_BREAKDOWN_TYPES.reduce(
        (sum, type) => sum + point[type],
        0,
      ),
    }))
    .sort((a, b) => a.day.getTime() - b.day.getTime())
}

function getLatestOverviewStorageComponents(
  chartPoints: OverviewStorageChartPoint[],
): Pick<ProjectOverviewStorageOverview, 'latestValue' | 'latestByType'> {
  const latest = chartPoints.at(-1)
  if (!latest) {
    return { latestValue: 0, latestByType: emptyStorageTotals() }
  }

  const latestByType = emptyStorageTotals()
  for (const type of OVERVIEW_STORAGE_BREAKDOWN_TYPES) {
    latestByType[type] = latest[type]
  }

  return { latestValue: latest.total, latestByType }
}

/** Stacked per-resource-type storage chart + per-type breakdowns for overview. */
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
      latestByType: emptyStorageTotals(),
      chartPoints: [],
      storageBreakdown: emptyStorageBreakdown(),
    }
  }

  const includeBreakdown =
    options?.includeBreakdown !== false && areUsageBreakdownQueriesEnabled()
  const queries = options?.queries

  const { from, to } = resolveOverviewUsagePeriod(dateRange, interval)

  // One dimensioned request covers every series; the per-type breakdowns stay
  // separate so each panel gets its own top-N instead of sharing one.
  const [series, ...breakdowns] = await Promise.all([
    fetchProjectUsageGaugeChartSeriesByResourceType(
      projectId,
      dateRange,
      STORAGE_GAUGE_METRIC,
      OVERVIEW_STORAGE_BREAKDOWN_OPTIONS.map((option) => ({
        key: option.value,
        resourceTypes: option.resourceTypes,
      })),
      interval,
      queries,
    ),
    ...OVERVIEW_STORAGE_BREAKDOWN_OPTIONS.map((option) =>
      includeBreakdown
        ? fetchUsageGaugeBreakdown(
            projectId,
            STORAGE_GAUGE_METRIC,
            from,
            to,
            ['resourceId', 'resourceType'],
            OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
            storageResourceTypeQueries(option.value, queries),
          )
        : Promise.resolve([]),
    ),
  ])

  const chartPoints = mergeOverviewStorageChartPoints(series.chartPointsBySeries)
  const previousChartPoints = mergeOverviewStorageChartPoints(
    series.previousChartPointsBySeries,
  )

  const latest = getLatestOverviewStorageComponents(chartPoints)
  const previousLatest = getLatestOverviewStorageComponents(previousChartPoints)

  const storageBreakdown = emptyStorageBreakdown()
  OVERVIEW_STORAGE_BREAKDOWN_OPTIONS.forEach((option, index) => {
    storageBreakdown[option.value] = breakdowns[index] ?? []
  })

  return {
    ...latest,
    changePercent: computeChangePercent(
      latest.latestValue,
      previousLatest.latestValue,
    ),
    chartPoints,
    storageBreakdown,
  }
}

/** Storage time series + top consumers for one resource family. */
export async function fetchProjectStorageResourceTypeUsageOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  breakdownType: OverviewStorageBreakdownType,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<StorageResourceTypeUsageOverview> {
  if (!projectId) {
    return { changePercent: 0, chartPoints: [], topConsumers: [] }
  }

  const includeBreakdown =
    options?.includeBreakdown !== false && areUsageBreakdownQueriesEnabled()
  const { from, to } = resolveOverviewUsagePeriod(dateRange, interval)
  const option = getOverviewStorageBreakdownOption(breakdownType)

  const [series, topConsumers] = await Promise.all([
    fetchProjectUsageGaugeChartSeriesByResourceType(
      projectId,
      dateRange,
      STORAGE_GAUGE_METRIC,
      [{ key: option.value, resourceTypes: option.resourceTypes }],
      interval,
      options?.queries,
    ),
    includeBreakdown
      ? fetchUsageGaugeBreakdown(
          projectId,
          STORAGE_GAUGE_METRIC,
          from,
          to,
          ['resourceId', 'resourceType'],
          OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
          storageResourceTypeQueries(breakdownType, options?.queries),
        )
      : Promise.resolve([]),
  ])

  const chartPoints = series.chartPointsBySeries.get(option.value) ?? []
  const previousChartPoints =
    series.previousChartPointsBySeries.get(option.value) ?? []

  return {
    changePercent: computeChangePercent(
      getUsageChartLatestValue(chartPoints),
      getUsageChartLatestValue(previousChartPoints),
    ),
    chartPoints,
    topConsumers,
  }
}

/** Latest bucket storage snapshot + top bucket breakdown (legacy overview hook). */
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
  const overview = await fetchProjectStorageResourceTypeUsageOverview(
    projectId,
    dateRange,
    'buckets',
    interval,
    options,
  )

  return {
    changePercent: overview.changePercent,
    latestValue: getUsageChartLatestValue(overview.chartPoints),
    topConsumers: overview.topConsumers,
  }
}

/** Origin image transformations time series + top bucket breakdown. */
export async function fetchProjectImageTransformationsUsageOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<StorageImageTransformationsOverview> {
  const includeBreakdown =
    options?.includeBreakdown !== false && areUsageBreakdownQueriesEnabled()
  const queries = options?.queries

  const [chartOverview, snapshotOverview] = await Promise.all([
    fetchProjectUsageGaugesChartOverview(
      projectId,
      dateRange,
      IMAGE_TRANSFORMATIONS_GAUGE_METRICS,
      interval,
      queries,
    ),
    includeBreakdown
      ? fetchProjectUsageGaugeSnapshotOverview(
          projectId,
          dateRange,
          IMAGE_TRANSFORMATIONS_GAUGE_METRIC,
          interval,
          { dimensions: ['resourceId', 'resourceType'] },
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
