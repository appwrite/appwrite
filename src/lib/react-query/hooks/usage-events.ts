/**
 * React Query hooks for project usage events (overview dashboard).
 */

import { queryOptions, useQuery, useQueries, keepPreviousData } from '@tanstack/react-query'
import { useMemo } from 'react'
import type { DateRange } from 'react-day-picker'
import { endOfDay, startOfDay, subDays } from 'date-fns'
import {
  fetchProjectBandwidthChartOverview,
  fetchProjectBandwidthTopConsumers,
  type ProjectBandwidthChartOverview,
  type ProjectBandwidthTopConsumersOverview,
} from '@/lib/usage/bandwidth-events'
import {
  fetchProjectRequestsChartOverview,
  fetchProjectRequestsTopEndpoints,
  type ProjectRequestsChartOverview,
  type ProjectRequestsTopEndpointsOverview,
} from '@/lib/usage/requests-events'
import { DEFAULT_STALE_TIME } from './constants'

function getDefaultOverviewDateRange(): DateRange {
  return {
    from: startOfDay(subDays(new Date(), 29)),
    to: endOfDay(new Date()),
  }
}

function normalizeDateRangeKey(dateRange: DateRange | undefined): {
  from: string
  to: string
} {
  const resolved = dateRange ?? getDefaultOverviewDateRange()
  const from = startOfDay(resolved.from ?? subDays(new Date(), 29)).toISOString()
  const to = endOfDay(resolved.to ?? new Date()).toISOString()
  return { from, to }
}

const usageEventsQueryOptionsBase = {
  staleTime: DEFAULT_STALE_TIME,
  retry: false,
  refetchOnMount: false as const,
  refetchOnWindowFocus: false as const,
  refetchOnReconnect: false as const,
  placeholderData: keepPreviousData,
}

export function bandwidthChartOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
) {
  const { from, to } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: ['usage-events', 'bandwidth', 'chart', 'project', projectId, from, to],
    queryFn: () =>
      fetchProjectBandwidthChartOverview(projectId!, {
        from: new Date(from),
        to: new Date(to),
      }),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function bandwidthTopConsumersQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
) {
  const { from, to } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: ['usage-events', 'bandwidth', 'top', 'project', projectId, from, to],
    queryFn: () =>
      fetchProjectBandwidthTopConsumers(projectId!, {
        from: new Date(from),
        to: new Date(to),
      }),
    enabled: !!projectId && enabled,
    ...usageEventsQueryOptionsBase,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function requestsChartOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
) {
  const { from, to } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: ['usage-events', 'requests', 'chart', 'project', projectId, from, to],
    queryFn: () =>
      fetchProjectRequestsChartOverview(projectId!, {
        from: new Date(from),
        to: new Date(to),
      }),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function requestsTopEndpointsQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
) {
  const { from, to } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: ['usage-events', 'requests', 'top', 'project', projectId, from, to],
    queryFn: () =>
      fetchProjectRequestsTopEndpoints(projectId!, {
        from: new Date(from),
        to: new Date(to),
      }),
    enabled: !!projectId && enabled,
    ...usageEventsQueryOptionsBase,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/** @deprecated Use bandwidthChartOverviewQueryOptions */
export function bandwidthOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
) {
  return bandwidthChartOverviewQueryOptions(projectId, dateRange)
}

/** @deprecated Use requestsChartOverviewQueryOptions */
export function requestsOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
) {
  return requestsChartOverviewQueryOptions(projectId, dateRange)
}

export function useProjectBandwidthChartOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
) {
  return useQuery(bandwidthChartOverviewQueryOptions(projectId, dateRange))
}

export function useProjectBandwidthTopConsumers(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
) {
  return useQuery(
    bandwidthTopConsumersQueryOptions(projectId, dateRange, enabled),
  )
}

export function useProjectRequestsChartOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
) {
  return useQuery(requestsChartOverviewQueryOptions(projectId, dateRange))
}

export function useProjectRequestsTopEndpoints(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
) {
  return useQuery(
    requestsTopEndpointsQueryOptions(projectId, dateRange, enabled),
  )
}

/** Last 30 days — matches project overview request charts. */
export function getProjectListRequestsChartDateRange(): DateRange {
  return {
    from: startOfDay(subDays(new Date(), 29)),
    to: endOfDay(new Date()),
  }
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
  const dateRange = getProjectListRequestsChartDateRange()
  const uniqueIds = useMemo(
    () => [...new Set(projectIds.filter(Boolean))],
    [projectIds],
  )

  const queries = useQueries({
    queries: uniqueIds.map((projectId) => ({
      ...requestsChartOverviewQueryOptions(projectId, dateRange),
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

/** @deprecated Use useProjectBandwidthChartOverview */
export function useProjectBandwidthOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  initialData?: ProjectBandwidthChartOverview,
) {
  return useQuery({
    ...bandwidthChartOverviewQueryOptions(projectId, dateRange),
    initialData,
  })
}

/** @deprecated Use useProjectRequestsChartOverview */
export function useProjectRequestsOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  initialData?: ProjectRequestsChartOverview,
) {
  return useQuery({
    ...requestsChartOverviewQueryOptions(projectId, dateRange),
    initialData,
  })
}

export type {
  ProjectBandwidthChartOverview,
  ProjectBandwidthTopConsumersOverview,
  ProjectRequestsChartOverview,
  ProjectRequestsTopEndpointsOverview,
  ProjectBandwidthChartOverview as ProjectBandwidthOverview,
  ProjectRequestsChartOverview as ProjectRequestsOverview,
}
