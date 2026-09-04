import type { DateRange } from 'react-day-picker'
import type { Models } from '@appwrite.io/console'
import {
  formatCompactBytes,
  formatCompactBytesAxis,
  formatCompactCount,
  formatCompactCountAxis,
} from '@/lib/usage/format-metric'
import {
  getUsageChartLatestValue,
  type DatabaseUsageChartOverview,
} from '@/lib/usage/database-usage'
import { fetchProjectUsageGaugesChartOverview } from '@/lib/usage/usage-gauges-common'
import {
  computeChangePercent,
  fetchProjectUsageChartOverview,
  sumUsageChartPoints,
  type FetchUsageOverviewOptions,
  type UsageChartInterval,
  type UsageChartPoint,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import {
  DEDICATED_DATABASE_USAGE_RESOURCE_TYPE,
  DEDICATED_DATABASE_USAGE_SERVICE,
  dedicatedDatabaseUsageServiceQueries,
} from '@/lib/usage/usage-resource-queries'

export {
  DEDICATED_DATABASE_USAGE_RESOURCE_TYPE,
  DEDICATED_DATABASE_USAGE_SERVICE,
}

export function formatDedicatedDatabasePercentTotal(value: number): string {
  return `${value.toFixed(1)}%`
}

export function formatDedicatedDatabasePercentValue(value: number): string {
  return `${value.toFixed(1)}%`
}

/** Instance storage used by a dedicated database (bytes). */
export const DEDICATED_DATABASE_STORAGE_METRIC =
  'dedicatedDatabases.storage' as const

/** Concurrent client connections on a dedicated database. */
export const DEDICATED_DATABASE_CONNECTIONS_METRIC =
  'dedicatedDatabases.connections' as const

/** CPU utilization for a dedicated database instance (0–100). */
export const DEDICATED_DATABASE_CPU_METRIC = 'dedicatedDatabases.cpu' as const

/** Memory utilization for a dedicated database instance (0–100). */
export const DEDICATED_DATABASE_MEMORY_METRIC =
  'dedicatedDatabases.memory' as const

/** Queries per second on a dedicated database instance. */
export const DEDICATED_DATABASE_QPS_METRIC = 'dedicatedDatabases.qps' as const

/** Disk read IOPS on a dedicated database instance. */
export const DEDICATED_DATABASE_IOPS_READ_METRIC =
  'dedicatedDatabases.iopsRead' as const

/** Disk write IOPS on a dedicated database instance. */
export const DEDICATED_DATABASE_IOPS_WRITE_METRIC =
  'dedicatedDatabases.iopsWrite' as const

export const DEDICATED_DATABASE_GAUGE_METRICS = [
  DEDICATED_DATABASE_STORAGE_METRIC,
  DEDICATED_DATABASE_CONNECTIONS_METRIC,
  DEDICATED_DATABASE_CPU_METRIC,
  DEDICATED_DATABASE_MEMORY_METRIC,
  DEDICATED_DATABASE_QPS_METRIC,
  DEDICATED_DATABASE_IOPS_READ_METRIC,
  DEDICATED_DATABASE_IOPS_WRITE_METRIC,
] as const

export type DedicatedDatabaseMetric =
  (typeof DEDICATED_DATABASE_GAUGE_METRICS)[number]

export type DedicatedDatabaseUsageChartOverview = DatabaseUsageChartOverview

export const DEDICATED_DATABASE_STORAGE_DESCRIPTION =
  'Storage used by this database instance over the selected period.'

export const DEDICATED_DATABASE_CONNECTIONS_DESCRIPTION =
  'Active client connections sampled for this database instance.'

export const DEDICATED_DATABASE_CPU_DESCRIPTION =
  'Average CPU utilization for this database instance.'

export const DEDICATED_DATABASE_MEMORY_DESCRIPTION =
  'Memory utilization relative to provisioned RAM for this database instance.'

export const DEDICATED_DATABASE_QPS_DESCRIPTION =
  'Queries per second handled by this database instance.'

export const DEDICATED_DATABASE_IOPS_READ_DESCRIPTION =
  'Disk read operations per second for instance storage.'

export const DEDICATED_DATABASE_IOPS_WRITE_DESCRIPTION =
  'Disk write operations per second for instance storage.'

export const DEDICATED_DATABASE_IOPS_DESCRIPTION =
  'Disk read and write operations per second for instance storage.'

export const DEDICATED_DATABASE_NETWORK_DESCRIPTION =
  'Inbound and outbound data transferred by this database instance during the selected period.'

export type DedicatedDatabaseNetworkMetricKind =
  | 'inbound'
  | 'outbound'
  | 'compute'

/** Event metric names in `projects_usage_events`: `dedicatedDatabases.{internalId}.*`. */
export function dedicatedDatabaseNetworkMetric(
  internalId: string,
  kind: DedicatedDatabaseNetworkMetricKind,
): string {
  return `dedicatedDatabases.${internalId}.${kind}`
}

/**
 * Infrastructure tenant id embedded in dedicated database network metric names.
 * Falls back to the public database id until the API exposes `tenant` directly.
 */
export function resolveDedicatedDatabaseUsageInternalId(
  databaseId: string,
  database?: Pick<Models.DedicatedDatabase, '$id'> | null,
): string {
  const tenant = (database as { tenant?: string } | null | undefined)?.tenant?.trim()
  if (tenant) return tenant
  const id = database?.$id?.trim()
  if (id) return id
  return databaseId.trim()
}

function dedicatedDatabaseGaugeFetchOptions(
  databaseId: string,
  ordinal?: number,
): {
  resourceId: string
  resourceType: typeof DEDICATED_DATABASE_USAGE_RESOURCE_TYPE
  includeBreakdown: false
  queries: string[]
  ordinal?: number
} {
  return {
    resourceId: databaseId,
    resourceType: DEDICATED_DATABASE_USAGE_RESOURCE_TYPE,
    includeBreakdown: false,
    queries: dedicatedDatabaseUsageServiceQueries(),
    ...(ordinal !== undefined ? { ordinal } : {}),
  }
}

function dedicatedDatabaseNetworkFetchOptions(
  queries?: string[],
): { queries: string[] } {
  return {
    queries: dedicatedDatabaseUsageServiceQueries(queries),
  }
}

export {
  formatCompactBytes as formatDedicatedDatabaseStorageTotal,
  formatCompactBytes as formatDedicatedDatabaseStorageValue,
  formatCompactBytes as formatDedicatedDatabaseNetworkTotal,
  formatCompactBytes as formatDedicatedDatabaseNetworkValue,
  formatCompactBytesAxis as formatDedicatedDatabaseStorageAxisValue,
  formatCompactBytesAxis as formatDedicatedDatabaseNetworkAxisValue,
  formatCompactCount as formatDedicatedDatabaseCountTotal,
  formatCompactCount as formatDedicatedDatabaseCountValue,
  formatCompactCountAxis as formatDedicatedDatabaseCountAxisValue,
}

export { sumUsageChartPoints, getUsageChartLatestValue }

export async function fetchDedicatedDatabaseMetricOverview(
  projectId: string,
  metric: DedicatedDatabaseMetric,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  const scopedOptions = options?.resourceId
    ? dedicatedDatabaseGaugeFetchOptions(options.resourceId, options.ordinal)
    : undefined

  return fetchProjectUsageGaugesChartOverview(
    projectId,
    dateRange,
    [metric],
    interval,
    scopedOptions?.queries ?? dedicatedDatabaseUsageServiceQueries(options?.queries),
    options?.logRetentionHours,
    options?.resourceId,
    options?.resourceType ?? DEDICATED_DATABASE_USAGE_RESOURCE_TYPE,
    options?.ordinal,
  )
}

async function fetchDedicatedDatabaseNetworkMetricOverview(
  projectId: string,
  internalId: string,
  kind: DedicatedDatabaseNetworkMetricKind,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  return fetchProjectUsageChartOverview(
    projectId,
    dateRange,
    [dedicatedDatabaseNetworkMetric(internalId, kind)],
    interval,
    dedicatedDatabaseNetworkFetchOptions(options?.queries).queries,
    options?.logRetentionHours,
  )
}

export async function fetchDedicatedDatabaseNetworkInboundOverview(
  projectId: string,
  internalId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  return fetchDedicatedDatabaseNetworkMetricOverview(
    projectId,
    internalId,
    'inbound',
    dateRange,
    interval,
    options,
  )
}

export async function fetchDedicatedDatabaseNetworkOutboundOverview(
  projectId: string,
  internalId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  return fetchDedicatedDatabaseNetworkMetricOverview(
    projectId,
    internalId,
    'outbound',
    dateRange,
    interval,
    options,
  )
}

export async function fetchDedicatedDatabaseNetworkComputeOverview(
  projectId: string,
  internalId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  return fetchDedicatedDatabaseNetworkMetricOverview(
    projectId,
    internalId,
    'compute',
    dateRange,
    interval,
    options,
  )
}

export async function fetchDedicatedDatabaseStorageOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  return fetchDedicatedDatabaseMetricOverview(
    projectId,
    DEDICATED_DATABASE_STORAGE_METRIC,
    dateRange,
    interval,
    options,
  )
}

export async function fetchDedicatedDatabaseConnectionsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  return fetchDedicatedDatabaseMetricOverview(
    projectId,
    DEDICATED_DATABASE_CONNECTIONS_METRIC,
    dateRange,
    interval,
    options,
  )
}

export async function fetchDedicatedDatabaseCpuOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  return fetchDedicatedDatabaseMetricOverview(
    projectId,
    DEDICATED_DATABASE_CPU_METRIC,
    dateRange,
    interval,
    options,
  )
}

export async function fetchDedicatedDatabaseMemoryOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  return fetchDedicatedDatabaseMetricOverview(
    projectId,
    DEDICATED_DATABASE_MEMORY_METRIC,
    dateRange,
    interval,
    options,
  )
}

export async function fetchDedicatedDatabaseQpsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  return fetchDedicatedDatabaseMetricOverview(
    projectId,
    DEDICATED_DATABASE_QPS_METRIC,
    dateRange,
    interval,
    options,
  )
}

export async function fetchDedicatedDatabaseIopsReadOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  return fetchDedicatedDatabaseMetricOverview(
    projectId,
    DEDICATED_DATABASE_IOPS_READ_METRIC,
    dateRange,
    interval,
    options,
  )
}

export async function fetchDedicatedDatabaseIopsWriteOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DedicatedDatabaseUsageChartOverview> {
  return fetchDedicatedDatabaseMetricOverview(
    projectId,
    DEDICATED_DATABASE_IOPS_WRITE_METRIC,
    dateRange,
    interval,
    options,
  )
}

/** Map usage chart points into monitor-style `{ timestamp, value }` series. */
export function usageChartPointsToMonitorSeries(
  points: UsageChartPoint[],
): { timestamp: number; value: number }[] {
  return points.map((point) => ({
    timestamp: point.day.getTime(),
    value: point.total,
  }))
}

/** Align two gauge series for dual-line charts (read/write IOPS). */
export function mergeDualUsageChartSeries(
  primary: UsageChartPoint[],
  secondary: UsageChartPoint[],
): { timestamp: number; value: number; secondaryValue: number }[] {
  const secondaryByTime = new Map(
    secondary.map((point) => [point.day.getTime(), point.total]),
  )
  return primary.map((point) => {
    const timestamp = point.day.getTime()
    return {
      timestamp,
      value: point.total,
      secondaryValue: secondaryByTime.get(timestamp) ?? 0,
    }
  })
}

export function getDedicatedDatabaseMetricChangePercent(
  overview: DedicatedDatabaseUsageChartOverview | undefined,
): number {
  return overview?.changePercent ?? 0
}

export function getDedicatedDatabaseGaugeHeadline(
  points: UsageChartPoint[],
): number {
  return getUsageChartLatestValue(points)
}

export function getDedicatedDatabaseRateHeadline(
  points: UsageChartPoint[],
): number {
  if (points.length === 0) return 0
  // Prefer latest sample for rates (QPS / IOPS); fall back to period average.
  const latest = getUsageChartLatestValue(points)
  if (latest > 0) return latest
  return sumUsageChartPoints(points) / points.length
}

export { computeChangePercent }
