import type { DateRange } from 'react-day-picker'
import {
  fetchProjectUsageEventBreakdown,
  mergeUsageBreakdownItems,
  type UsageBreakdownItem,
  type UsageEventBreakdownDimension,
} from '@/lib/usage/usage-events-common'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import {
  DATABASE_READS_EVENT_METRICS,
  DATABASE_WRITES_EVENT_METRICS,
} from '@/lib/usage/database-usage'

export type DatabaseOperationsBreakdownSection = {
  dimension: UsageEventBreakdownDimension
  title: string
  description: string
  metricId: string
  labelVariant: 'mono' | 'default'
}

/** Breakdown dimensions that map cleanly to database read/write operations. */
export const DATABASE_OPERATIONS_BREAKDOWN_SECTIONS: readonly DatabaseOperationsBreakdownSection[] =
  [
    {
      dimension: 'resource',
      title: 'Resources',
      description: 'Operations grouped by resource.',
      metricId: 'breakdown-resources',
      labelVariant: 'default',
    },
    {
      dimension: 'ip',
      title: 'IP addresses',
      description: 'Operations grouped by caller IP address.',
      metricId: 'breakdown-ip',
      labelVariant: 'mono',
    },
    {
      dimension: 'service',
      title: 'Services',
      description:
        'Operations grouped by Appwrite database API (TablesDB, DocumentsDB, VectorsDB, legacy).',
      metricId: 'breakdown-service',
      labelVariant: 'default',
    },
    {
      dimension: 'path',
      title: 'API paths',
      description: 'API endpoint paths driving database operations.',
      metricId: 'breakdown-path',
      labelVariant: 'mono',
    },
  ] as const

export async function fetchProjectDatabaseReadsBreakdown(
  projectId: string,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  queries?: string[],
  resourceId?: string,
): Promise<UsageBreakdownItem[]> {
  const breakdowns = await Promise.all(
    DATABASE_READS_EVENT_METRICS.map((metric) =>
      fetchProjectUsageEventBreakdown(
        projectId,
        metric,
        dateRange,
        dimension,
        limit,
        queries,
        resourceId,
      ),
    ),
  )

  return mergeUsageBreakdownItems(breakdowns, limit)
}

export async function fetchProjectDatabaseWritesBreakdown(
  projectId: string,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  queries?: string[],
  resourceId?: string,
): Promise<UsageBreakdownItem[]> {
  const breakdowns = await Promise.all(
    DATABASE_WRITES_EVENT_METRICS.map((metric) =>
      fetchProjectUsageEventBreakdown(
        projectId,
        metric,
        dateRange,
        dimension,
        limit,
        queries,
        resourceId,
      ),
    ),
  )

  return mergeUsageBreakdownItems(breakdowns, limit)
}

export type { UsageBreakdownItem, UsageEventBreakdownDimension }
