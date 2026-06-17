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
  fetchProjectRequestsChartOverview,
  fetchProjectRequestsOverview,
  type ProjectRequestsChartOverview,
  type ProjectRequestsOverview,
} from '@/lib/usage/requests-events'
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
  ProjectBandwidthOverview,
  ProjectRequestsOverview,
  ProjectRequestsChartOverview,
  ProjectBandwidthOverview as ProjectBandwidthChartOverview,
  ProjectBandwidthOverview as ProjectBandwidthTopConsumersOverview,
  ProjectRequestsOverview as ProjectRequestsTopEndpointsOverview,
}

export type { UsageChartInterval } from '@/lib/usage/chart-interval'
export { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
