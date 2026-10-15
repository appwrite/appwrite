import {
  buildFilterQueryString,
  mapToQueryParam,
  type CompactFilterKey,
} from '@/lib/table-filters'
import {
  getUsageCategoryIdForOverviewChartTab,
  type OverviewChartTabId,
} from '@/lib/overview-chart-tabs'
import {
  getUsageResourceFilterEntries,
  type UsageBreakdownFilterEntry,
} from '@/lib/usage/usage-resource-filters'
import type { ComputeBreakdownResource } from '@/lib/usage/resolve-compute-breakdown-resources'
import type { DatabaseBreakdownResource } from '@/lib/usage/resolve-database-breakdown-resources'
import type { StorageBreakdownResource } from '@/lib/usage/resolve-storage-breakdown-resources'

export type OverviewBreakdownMetric =
  | 'bandwidth'
  | 'requests'
  | 'storage'
  | 'executions'
  | 'gbhours'

const OVERVIEW_METRIC_TO_CHART_TAB: Record<
  OverviewBreakdownMetric,
  OverviewChartTabId
> = {
  bandwidth: 'bandwidth',
  requests: 'requests',
  storage: 'storage',
  executions: 'executions',
  gbhours: 'gbhours',
}

export function getOverviewBreakdownUsageCategoryId(
  metric: OverviewBreakdownMetric,
): string {
  return getUsageCategoryIdForOverviewChartTab(
    OVERVIEW_METRIC_TO_CHART_TAB[metric],
  )
}

export function buildUsageBreakdownFilterQueryParam(
  filters: UsageBreakdownFilterEntry[],
): string | undefined {
  const map = new Map<CompactFilterKey, string>()

  for (const { dimension, value } of filters) {
    const trimmed = value.trim()
    if (!trimmed) continue
    const key: CompactFilterKey = { c: dimension, o: 'equal', v: trimmed }
    map.set(key, buildFilterQueryString('equal', dimension, trimmed))
  }

  if (map.size === 0) return undefined
  return mapToQueryParam(map)
}

export function getOverviewEndpointBreakdownFilters(
  path: string,
): UsageBreakdownFilterEntry[] {
  const trimmed = path.trim()
  if (!trimmed) return []
  return [{ dimension: 'path', value: trimmed }]
}

export function getOverviewResourceBreakdownFilters(
  resourceId: string,
  resources: {
    computeResource?: ComputeBreakdownResource
    storageResource?: StorageBreakdownResource
    databaseResource?: DatabaseBreakdownResource
  },
): UsageBreakdownFilterEntry[] {
  return getUsageResourceFilterEntries(resourceId, resources)
}

export function getOverviewBreakdownUsageLinkProps(
  projectId: string,
  metric: OverviewBreakdownMetric,
  filters: UsageBreakdownFilterEntry[],
) {
  const query = buildUsageBreakdownFilterQueryParam(filters)

  return {
    to: '/projects/$projectId/usage/$categoryId' as const,
    params: {
      projectId,
      categoryId: getOverviewBreakdownUsageCategoryId(metric),
    },
    search: query ? { query } : undefined,
  }
}
