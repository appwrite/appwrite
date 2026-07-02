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
      dimension: 'resourceId',
      title: 'Resource IDs',
      description: 'Operations grouped by database resource ID.',
      metricId: 'breakdown-resource-id',
      labelVariant: 'mono',
    },
    {
      dimension: 'resource',
      title: 'Resource types',
      description: 'Operations grouped by resource type.',
      metricId: 'breakdown-resource-type',
      labelVariant: 'default',
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
      ),
    ),
  )

  return mergeUsageBreakdownItems(breakdowns, limit)
}

export type { UsageBreakdownItem, UsageEventBreakdownDimension }
