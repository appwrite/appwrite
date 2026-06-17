/**
 * React Query hooks for project usage events (overview dashboard).
 */

import { queryOptions, useQuery, useQueries, keepPreviousData } from '@tanstack/react-query'
import { useMemo } from 'react'
import type { DateRange } from 'react-day-picker'
import {
  fetchProjectBandwidthOverview,
  type ProjectBandwidthOverview,
} from '@/lib/usage/bandwidth-events'
import {
  fetchProjectExecutionsOverview,
  type ProjectExecutionsOverview,
} from '@/lib/usage/executions-events'
import {
  fetchProjectGbHoursOverview,
  type ProjectGbHoursOverview,
} from '@/lib/usage/gb-hours-events'
import {
  fetchProjectRequestsChartOverview,
  fetchProjectRequestsOverview,
  type ProjectRequestsChartOverview,
  type ProjectRequestsOverview,
} from '@/lib/usage/requests-events'
import {
  fetchProjectStorageOverview,
  type ProjectStorageOverview,
} from '@/lib/usage/storage-gauges'
import {
  fetchComputeBreakdownResources,
  normalizeComputeBreakdownResourceIds,
} from '@/lib/usage/resolve-compute-breakdown-resources'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import {
  getDefaultUsageChartDateRange,
  resolveUsageDateBounds,
} from '@/lib/usage/usage-date-range'
import { DEFAULT_STALE_TIME } from './constants'

function normalizeDateRangeKey(dateRange: DateRange | undefined): {
  from: string
  to: string
} {
  const { from, to } = resolveUsageDateBounds(dateRange)
  return { from: from.toISOString(), to: to.toISOString() }
}

const usageEventsQueryOptionsBase = {
  staleTime: DEFAULT_STALE_TIME,
  retry: false,
  refetchOnMount: false as const,
  refetchOnWindowFocus: false as const,
  refetchOnReconnect: false as const,
  placeholderData: keepPreviousData,
  meta: {
    skipInitialLoader: true,
  },
}

export function bandwidthOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { from, to } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'bandwidth',
      'overview',
      'project',
      projectId,
      from,
      to,
      interval,
    ],
    queryFn: () =>
      fetchProjectBandwidthOverview(
        projectId!,
        {
          from: new Date(from),
          to: new Date(to),
        },
        interval,
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function requestsOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { from, to } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'requests',
      'overview',
      'project',
      projectId,
      from,
      to,
      interval,
    ],
    queryFn: () =>
      fetchProjectRequestsOverview(
        projectId!,
        {
          from: new Date(from),
          to: new Date(to),
        },
        interval,
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function executionsOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { from, to } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'executions',
      'overview',
      'project',
      projectId,
      from,
      to,
      interval,
    ],
    queryFn: () =>
      fetchProjectExecutionsOverview(
        projectId!,
        {
          from: new Date(from),
          to: new Date(to),
        },
        interval,
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function gbHoursOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { from, to } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'gb-hours',
      'overview',
      'project',
      projectId,
      from,
      to,
      interval,
    ],
    queryFn: () =>
      fetchProjectGbHoursOverview(
        projectId!,
        {
          from: new Date(from),
          to: new Date(to),
        },
        interval,
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function storageOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { from, to } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'storage',
      'overview',
      'project',
      projectId,
      from,
      to,
      interval,
    ],
    queryFn: () =>
      fetchProjectStorageOverview(
        projectId!,
        {
          from: new Date(from),
          to: new Date(to),
        },
        interval,
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/** Org project list sparklines — chart only, no breakdown dimensions payload in query key. */
export function requestsChartOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { from, to } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'requests',
      'chart',
      'project',
      projectId,
      from,
      to,
      interval,
    ],
    queryFn: () =>
      fetchProjectRequestsChartOverview(
        projectId!,
        {
          from: new Date(from),
          to: new Date(to),
        },
        interval,
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/** @deprecated Use bandwidthOverviewQueryOptions */
export function bandwidthChartOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return bandwidthOverviewQueryOptions(projectId, dateRange, interval)
}

/** @deprecated Use bandwidthOverviewQueryOptions */
export function bandwidthTopConsumersQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return {
    ...bandwidthOverviewQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  }
}

/** @deprecated Use requestsOverviewQueryOptions */
export function requestsTopEndpointsQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return {
    ...requestsOverviewQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  }
}

export function useProjectBandwidthOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...bandwidthOverviewQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectRequestsOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...requestsOverviewQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectExecutionsOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...executionsOverviewQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectGbHoursOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...gbHoursOverviewQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectStorageOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...storageOverviewQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function computeBreakdownResourcesQueryOptions(
  projectId: string | null | undefined,
  resourceIds: string[],
) {
  const normalizedIds = normalizeComputeBreakdownResourceIds(resourceIds)

  return queryOptions({
    queryKey: [
      'usage-breakdown',
      'compute-resources',
      'project',
      projectId,
      normalizedIds.join(','),
    ],
    queryFn: () =>
      fetchComputeBreakdownResources(projectId!, normalizedIds),
    enabled: !!projectId && normalizedIds.length > 0,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
    meta: {
      skipInitialLoader: true,
    },
  })
}

/** Resolve executions / GB-hours breakdown IDs to function and site names (background). */
export function useComputeBreakdownResources(
  projectId: string | null | undefined,
  resourceIds: string[],
  enabled = true,
) {
  const normalizedIds = useMemo(
    () => normalizeComputeBreakdownResourceIds(resourceIds),
    [resourceIds],
  )

  return useQuery({
    ...computeBreakdownResourcesQueryOptions(projectId, normalizedIds),
    enabled: enabled && !!projectId && normalizedIds.length > 0,
  })
}

/** @deprecated Use useProjectBandwidthOverview */
export function useProjectBandwidthChartOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useProjectBandwidthOverview(projectId, dateRange, true, interval)
}

/** @deprecated Use useProjectBandwidthOverview */
export function useProjectBandwidthTopConsumers(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useProjectBandwidthOverview(projectId, dateRange, enabled, interval)
}

/** @deprecated Use useProjectRequestsOverview */
export function useProjectRequestsChartOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useProjectRequestsOverview(projectId, dateRange, true, interval)
}

/** @deprecated Use useProjectRequestsOverview */
export function useProjectRequestsTopEndpoints(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useProjectRequestsOverview(projectId, dateRange, enabled, interval)
}

/** Last 24 hours — matches project overview usage charts. */
export function getProjectListRequestsChartDateRange(): DateRange {
  return getDefaultUsageChartDateRange()
}

export type ProjectListRequestsUsageEntry = {
  isLoading: boolean
  isError: boolean
  data: ProjectRequestsChartOverview | undefined
}

/**
 * Fetches request usage chart data for many projects in parallel (org project cards).
 */
export function useProjectListRequestsUsage(
  projectIds: string[],
  enabled: boolean,
): Map<string, ProjectListRequestsUsageEntry> {
  const dateRange = useMemo(() => getProjectListRequestsChartDateRange(), [])
  const uniqueIds = useMemo(
    () => [...new Set(projectIds.filter(Boolean))],
    [projectIds],
  )

  const queries = useQueries({
    queries: uniqueIds.map((projectId) => ({
      ...requestsChartOverviewQueryOptions(
        projectId,
        dateRange,
        DEFAULT_USAGE_CHART_INTERVAL,
      ),
      enabled: enabled && !!projectId,
    })),
  })

  return useMemo(() => {
    const map = new Map<string, ProjectListRequestsUsageEntry>()
    uniqueIds.forEach((projectId, index) => {
      const query = queries[index]
      map.set(projectId, {
        isLoading: query.isPending && !query.data && !query.isError,
        isError: query.isError,
        data: query.data,
      })
    })
    return map
  }, [uniqueIds, queries])
}

export type {
  ComputeBreakdownResourceMap,
} from '@/lib/usage/resolve-compute-breakdown-resources'

export type {
  ProjectBandwidthOverview,
  ProjectExecutionsOverview,
  ProjectGbHoursOverview,
  ProjectStorageOverview,
  ProjectRequestsOverview,
  ProjectRequestsChartOverview,
  ProjectBandwidthOverview as ProjectBandwidthChartOverview,
  ProjectBandwidthOverview as ProjectBandwidthTopConsumersOverview,
  ProjectRequestsOverview as ProjectRequestsTopEndpointsOverview,
}

export type { UsageChartInterval } from '@/lib/usage/chart-interval'
export { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
