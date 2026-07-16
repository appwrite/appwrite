import type { DateRange } from 'react-day-picker'
import {
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
import { fetchProjectUsageGaugesChartOverview } from '@/lib/usage/usage-gauges-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'

/** Legacy Databases API read operations. */
export const DATABASE_READS_LEGACY_METRIC = 'databases.operations.reads' as const

/** DocumentsDB read operations. */
export const DATABASE_READS_DOCUMENTSDB_METRIC =
  'documentsdb.databases.operations.reads' as const

/** VectorsDB read operations. */
export const DATABASE_READS_VECTORSDB_METRIC =
  'vectorsdb.databases.operations.reads' as const

export const DATABASE_READS_EVENT_METRICS = [
  DATABASE_READS_LEGACY_METRIC,
  DATABASE_READS_DOCUMENTSDB_METRIC,
  DATABASE_READS_VECTORSDB_METRIC,
] as const

export const DATABASE_WRITES_LEGACY_METRIC =
  'databases.operations.writes' as const

export const DATABASE_WRITES_DOCUMENTSDB_METRIC =
  'documentsdb.databases.operations.writes' as const

export const DATABASE_WRITES_VECTORSDB_METRIC =
  'vectorsdb.databases.operations.writes' as const

export const DATABASE_WRITES_EVENT_METRICS = [
  DATABASE_WRITES_LEGACY_METRIC,
  DATABASE_WRITES_DOCUMENTSDB_METRIC,
  DATABASE_WRITES_VECTORSDB_METRIC,
] as const

export const DATABASE_COLLECTIONS_LEGACY_METRIC = 'collections' as const

export const DATABASE_COLLECTIONS_DOCUMENTSDB_METRIC =
  'documentsdb.collections' as const

export const DATABASE_COLLECTIONS_VECTORSDB_METRIC =
  'vectorsdb.collections' as const

export const DATABASE_COLLECTIONS_GAUGE_METRICS = [
  DATABASE_COLLECTIONS_LEGACY_METRIC,
  DATABASE_COLLECTIONS_DOCUMENTSDB_METRIC,
  DATABASE_COLLECTIONS_VECTORSDB_METRIC,
] as const

export const DATABASE_DOCUMENTS_LEGACY_METRIC = 'documents' as const

export const DATABASE_DOCUMENTS_DOCUMENTSDB_METRIC =
  'documentsdb.documents' as const

export const DATABASE_DOCUMENTS_VECTORSDB_METRIC =
  'vectorsdb.documents' as const

export const DATABASE_DOCUMENTS_GAUGE_METRICS = [
  DATABASE_DOCUMENTS_LEGACY_METRIC,
  DATABASE_DOCUMENTS_DOCUMENTSDB_METRIC,
  DATABASE_DOCUMENTS_VECTORSDB_METRIC,
] as const

export type DatabaseUsageChartPoint = UsageChartPoint

export interface DatabaseUsageChartOverview {
  changePercent: number
  chartPoints: DatabaseUsageChartPoint[]
}

export const DATABASE_READS_DESCRIPTION =
  'Document read operations across all databases. Each row returned counts as one read.'

export const DATABASE_WRITES_DESCRIPTION =
  'Create, update, and delete operations across all databases. Each mutation counts as one write.'

export const DATABASE_READS_FOR_DATABASE_DESCRIPTION =
  'Read operations for this database. Each row returned counts as one read.'

export const DATABASE_WRITES_FOR_DATABASE_DESCRIPTION =
  'Create, update, and delete operations for this database. Each mutation counts as one write.'

export const DATABASE_COLLECTIONS_DESCRIPTION =
  'Total collections (tables) across all databases in your project.'

export const DATABASE_DOCUMENTS_DESCRIPTION =
  'Total rows stored across all collections in your project.'

export const DATABASE_READS_AND_WRITES_DOCS_HREF =
  '/docs/advanced/platform/database-reads-and-writes'

export const DATABASE_TABLES_DOCS_HREF = '/docs/products/databases/tables'

export const DATABASE_ROWS_DOCS_HREF = '/docs/products/databases/rows'

export function formatDatabaseOperationsTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatDatabaseOperationsValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatDatabaseCountTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatDatabaseCountValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export {
  formatCompactCountAxis as formatDatabaseOperationsAxisValue,
  formatCompactCountAxis as formatDatabaseCountAxisValue,
} from '@/lib/usage/format-metric'

export { sumUsageChartPoints } from '@/lib/usage/usage-events-common'

/** Latest snapshot value from a gauge time series (not a sum over the period). */
export function getUsageChartLatestValue(points: UsageChartPoint[]): number {
  if (points.length === 0) return 0
  return points[points.length - 1]?.total ?? 0
}

async function fetchMergedEventMetricsChartOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DatabaseUsageChartOverview> {
  const results = await Promise.all(
    metrics.map((metric) =>
      fetchProjectUsageMetricSeriesOverview(
        projectId,
        metric,
        dateRange,
        interval,
        [],
        0,
        options,
      ),
    ),
  )

  const chartPoints = mergeChartPointsSeries(
    results.map((result) => result.chartPoints),
  )
  const previousChartPoints = mergeChartPointsSeries(
    results.map((result) => result.previousChartPoints),
  )

  return {
    chartPoints,
    changePercent: computeChangePercent(
      sumUsageChartPoints(chartPoints),
      sumUsageChartPoints(previousChartPoints),
    ),
  }
}

/** Merged read operations across legacy, DocumentsDB, and VectorsDB APIs. */
export async function fetchProjectDatabaseReadsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DatabaseUsageChartOverview> {
  return fetchMergedEventMetricsChartOverview(
    projectId,
    dateRange,
    DATABASE_READS_EVENT_METRICS,
    interval,
    options,
  )
}

/** Merged write operations across legacy, DocumentsDB, and VectorsDB APIs. */
export async function fetchProjectDatabaseWritesOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DatabaseUsageChartOverview> {
  return fetchMergedEventMetricsChartOverview(
    projectId,
    dateRange,
    DATABASE_WRITES_EVENT_METRICS,
    interval,
    options,
  )
}

/** Merged collection counts across all database products. */
export async function fetchProjectDatabaseCollectionsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DatabaseUsageChartOverview> {
  return fetchProjectUsageGaugesChartOverview(
    projectId,
    dateRange,
    DATABASE_COLLECTIONS_GAUGE_METRICS,
    interval,
    options?.queries,
    options?.logRetentionHours,
  )
}

/** Merged document counts across all database products. */
export async function fetchProjectDatabaseDocumentsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<DatabaseUsageChartOverview> {
  return fetchProjectUsageGaugesChartOverview(
    projectId,
    dateRange,
    DATABASE_DOCUMENTS_GAUGE_METRICS,
    interval,
    options?.queries,
    options?.logRetentionHours,
  )
}
