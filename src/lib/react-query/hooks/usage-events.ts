/**
 * React Query hooks for project usage events (overview dashboard).
 */

import {
  queryOptions,
  useQuery,
  useQueries,
  useQueryClient,
  type Query,
  type QueryClient,
} from '@tanstack/react-query'
import { useMemo } from 'react'
import type { DateRange } from 'react-day-picker'
import {
  fetchProjectBandwidthOverview,
  type ProjectBandwidthOverview,
} from '@/lib/usage/bandwidth-events'
import {
  fetchProjectExecutionsOverview,
  fetchProjectFunctionExecutionsOverview,
  fetchProjectSiteExecutionsOverview,
  type ProjectExecutionsOverview,
} from '@/lib/usage/executions-events'
import {
  fetchProjectFunctionGbHoursOverview,
  fetchProjectGbHoursOverview,
  fetchProjectSiteGbHoursOverview,
  type ProjectGbHoursOverview,
} from '@/lib/usage/gb-hours-events'
import {
  fetchProjectRequestsChartOverview,
  fetchProjectRequestsOverview,
  type ProjectRequestsChartOverview,
  type ProjectRequestsOverview,
} from '@/lib/usage/requests-events'
import {
  fetchProjectRequestsBreakdown,
  REQUESTS_BREAKDOWN_SECTIONS,
  type RequestsBreakdownSection,
  type UsageBreakdownItem,
} from '@/lib/usage/requests-breakdowns'
import {
  fetchProjectBandwidthBreakdown,
  BANDWIDTH_BREAKDOWN_SECTIONS,
  type BandwidthBreakdownSection,
} from '@/lib/usage/bandwidth-breakdowns'
import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'
import { USAGE_BREAKDOWN_DRAWER_LIMIT } from '@/lib/usage/breakdown-limits'
import {
  fetchProjectImageTransformationsUsageOverview,
  fetchProjectStorageBuildsOverview,
  fetchProjectStorageDeploymentsOverview,
  fetchProjectStorageFilesUsageOverview,
  fetchProjectOverviewStorageOverview,
  fetchProjectStorageOverview,
  type ProjectOverviewStorageOverview,
  type StorageFilesUsageOverview,
  type StorageImageTransformationsOverview,
  type StorageUsageChartOverview,
} from '@/lib/usage/storage-usage'
import type { ProjectStorageOverview } from '@/lib/usage/storage-gauges'
import {
  fetchStorageBreakdownResources,
  normalizeStorageBreakdownResourceIds,
} from '@/lib/usage/resolve-storage-breakdown-resources'
import {
  fetchComputeBreakdownResources,
  normalizeComputeBreakdownResourceIds,
} from '@/lib/usage/resolve-compute-breakdown-resources'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import {
  getStableUsageChartDateRange,
  getUsageChartQueryRangeKeyPart,
  resolveUsageChartFetchBounds,
  shouldRefetchUsageChartOnMount,
} from '@/lib/usage/usage-date-range'
import { DEFAULT_STALE_TIME } from './constants'
import {
  fetchProjectDatabaseCollectionsOverview,
  fetchProjectDatabaseDocumentsOverview,
  fetchProjectDatabaseReadsOverview,
  fetchProjectDatabaseWritesOverview,
  type DatabaseUsageChartOverview,
} from '@/lib/usage/database-usage'
import {
  DATABASE_OPERATIONS_BREAKDOWN_SECTIONS,
  fetchProjectDatabaseReadsBreakdown,
  fetchProjectDatabaseWritesBreakdown,
  type DatabaseOperationsBreakdownSection,
} from '@/lib/usage/database-operations-breakdowns'
import {
  fetchDatabaseBreakdownResources,
  normalizeDatabaseBreakdownResourceIds,
} from '@/lib/usage/resolve-database-breakdown-resources'
import {
  fetchTableBreakdownResources,
  normalizeTableBreakdownResourceLabels,
} from '@/lib/usage/resolve-table-breakdown-resources'
import {
  fetchProjectRealtimeBandwidthOverview,
  fetchProjectRealtimeConnectionsOverview,
  fetchProjectRealtimeMessagesOverview,
  type RealtimeBandwidthOverview,
  type RealtimeUsageChartOverview,
} from '@/lib/usage/realtime-usage'
import {
  fetchProjectAuthMauOverview,
  fetchProjectAuthOtpOverview,
  fetchProjectAuthSignupsOverview,
  type AuthUsageChartOverview,
} from '@/lib/usage/auth-usage'
import {
  fetchProjectAvatarsScreenshotsOverview,
  type AvatarsUsageChartOverview,
} from '@/lib/usage/avatars-usage'
import {
  fetchProjectMessagingMessagesOverview,
  fetchProjectMessagingSmsOverview,
  fetchProjectMessagingTopicsOverview,
  type MessagingUsageChartOverview,
} from '@/lib/usage/messaging-usage'
import {
  fetchProjectWebhooksCountOverview,
  fetchProjectWebhooksEventsFailedOverview,
  fetchProjectWebhooksEventsSentOverview,
  type WebhooksUsageChartOverview,
} from '@/lib/usage/webhooks-usage'

function normalizeDateRangeKey(dateRange: DateRange | undefined): {
  rangeKeyPart: string
  getBounds: () => { from: Date; to: Date }
  refetchOnMountRolling: boolean
} {
  const rangeKeyPart = getUsageChartQueryRangeKeyPart(dateRange)
  return {
    rangeKeyPart,
    getBounds: () => resolveUsageChartFetchBounds(dateRange),
    refetchOnMountRolling: shouldRefetchUsageChartOnMount(rangeKeyPart),
  }
}

function getProjectIdFromUsageQueryKey(
  queryKey: readonly unknown[],
): string | undefined {
  const projectIndex = queryKey.indexOf('project')
  if (projectIndex === -1) return undefined
  const projectId = queryKey[projectIndex + 1]
  return typeof projectId === 'string' ? projectId : undefined
}

/** Keep chart data when filters change, but not when switching projects. */
function keepPreviousUsageChartDataForProject<T>(
  currentProjectId: string | null | undefined,
) {
  return (
    previousData: T | undefined,
    previousQuery: Query<T> | undefined,
  ): T | undefined => {
    if (previousData === undefined || !previousQuery || !currentProjectId) {
      return undefined
    }

    const previousProjectId = getProjectIdFromUsageQueryKey(
      previousQuery.queryKey,
    )
    if (previousProjectId !== currentProjectId) {
      return undefined
    }

    return previousData
  }
}

const usageEventsQueryOptionsBase = {
  staleTime: DEFAULT_STALE_TIME,
  retry: false,
  refetchOnMount: false as const,
  refetchOnWindowFocus: false as const,
  refetchOnReconnect: false as const,
  meta: {
    skipInitialLoader: true,
  },
}

function usageOverviewQueryScope(includeBreakdown: boolean): 'overview' | 'chart' {
  return includeBreakdown ? 'overview' : 'chart'
}

/** Reuse chart-only or overview cache when toggling breakdown on tab switch. */
function usageOverviewPlaceholderData<T>(
  queryClient: QueryClient,
  projectId: string | null | undefined,
  siblingQueryKey: readonly unknown[],
) {
  return (
    previousData: T | undefined,
    previousQuery: Query<T> | undefined,
  ): T | undefined => {
    const kept = keepPreviousUsageChartDataForProject<T>(projectId)(
      previousData,
      previousQuery,
    )
    if (kept !== undefined) {
      return kept
    }

    if (!projectId) {
      return undefined
    }

    return queryClient.getQueryData<T>(siblingQueryKey)
  }
}

export function bandwidthOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'bandwidth',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectBandwidthOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function requestsOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'requests',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectRequestsOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function executionsOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'executions',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectExecutionsOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function functionExecutionsOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'function-executions',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectFunctionExecutionsOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function siteExecutionsOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'site-executions',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectSiteExecutionsOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function gbHoursOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'gb-hours',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectGbHoursOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function functionGbHoursOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'function-gb-hours',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectFunctionGbHoursOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function siteGbHoursOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'site-gb-hours',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectSiteGbHoursOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function storageOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'storage',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectStorageOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function overviewStorageOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'storage',
      'overview',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectOverviewStorageOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/** Org project list sparklines — chart only, no breakdown dimensions payload in query key. */
export function requestsChartOverviewQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'requests',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectRequestsChartOverview(
        projectId!,
        getBounds(),
        interval,
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
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
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...bandwidthOverviewQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData: usageOverviewPlaceholderData<ProjectBandwidthOverview>(
      queryClient,
      projectId,
      bandwidthOverviewQueryOptions(
        projectId,
        dateRange,
        interval,
        !includeBreakdown,
      ).queryKey,
    ),
  })
}

export function useProjectRequestsOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...requestsOverviewQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData: usageOverviewPlaceholderData<ProjectRequestsOverview>(
      queryClient,
      projectId,
      requestsOverviewQueryOptions(
        projectId,
        dateRange,
        interval,
        !includeBreakdown,
      ).queryKey,
    ),
  })
}

export function useProjectExecutionsOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...executionsOverviewQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData: usageOverviewPlaceholderData<ProjectExecutionsOverview>(
      queryClient,
      projectId,
      executionsOverviewQueryOptions(
        projectId,
        dateRange,
        interval,
        !includeBreakdown,
      ).queryKey,
    ),
  })
}

export function useProjectFunctionExecutionsOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...functionExecutionsOverviewQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData: usageOverviewPlaceholderData<ProjectExecutionsOverview>(
      queryClient,
      projectId,
      functionExecutionsOverviewQueryOptions(
        projectId,
        dateRange,
        interval,
        !includeBreakdown,
      ).queryKey,
    ),
  })
}

export function useProjectSiteExecutionsOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...siteExecutionsOverviewQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData: usageOverviewPlaceholderData<ProjectExecutionsOverview>(
      queryClient,
      projectId,
      siteExecutionsOverviewQueryOptions(
        projectId,
        dateRange,
        interval,
        !includeBreakdown,
      ).queryKey,
    ),
  })
}

export function useProjectGbHoursOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...gbHoursOverviewQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData: usageOverviewPlaceholderData<ProjectGbHoursOverview>(
      queryClient,
      projectId,
      gbHoursOverviewQueryOptions(
        projectId,
        dateRange,
        interval,
        !includeBreakdown,
      ).queryKey,
    ),
  })
}

export function useProjectFunctionGbHoursOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...functionGbHoursOverviewQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData: usageOverviewPlaceholderData<ProjectGbHoursOverview>(
      queryClient,
      projectId,
      functionGbHoursOverviewQueryOptions(
        projectId,
        dateRange,
        interval,
        !includeBreakdown,
      ).queryKey,
    ),
  })
}

export function useProjectSiteGbHoursOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...siteGbHoursOverviewQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData: usageOverviewPlaceholderData<ProjectGbHoursOverview>(
      queryClient,
      projectId,
      siteGbHoursOverviewQueryOptions(
        projectId,
        dateRange,
        interval,
        !includeBreakdown,
      ).queryKey,
    ),
  })
}

export function useProjectStorageOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...storageOverviewQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData: usageOverviewPlaceholderData<ProjectStorageOverview>(
      queryClient,
      projectId,
      storageOverviewQueryOptions(
        projectId,
        dateRange,
        interval,
        !includeBreakdown,
      ).queryKey,
    ),
  })
}

export function useProjectOverviewStorageOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...overviewStorageOverviewQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData: usageOverviewPlaceholderData<ProjectOverviewStorageOverview>(
      queryClient,
      projectId,
      overviewStorageOverviewQueryOptions(
        projectId,
        dateRange,
        interval,
        !includeBreakdown,
      ).queryKey,
    ),
  })
}

function storageFilesUsageQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } =
    normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'storage',
      'files',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectStorageFilesUsageOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function storageGaugeChartQueryOptions(
  scope: 'deployments' | 'builds',
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } =
    normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'storage',
      scope,
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      scope === 'deployments'
        ? fetchProjectStorageDeploymentsOverview(
            projectId!,
            getBounds(),
            interval,
          )
        : fetchProjectStorageBuildsOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function imageTransformationsUsageQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } =
    normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'storage',
      'image-transformations',
      usageOverviewQueryScope(includeBreakdown),
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectImageTransformationsUsageOverview(
        projectId!,
        getBounds(),
        interval,
        { includeBreakdown },
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectStorageFilesUsage(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...storageFilesUsageQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData: usageOverviewPlaceholderData<StorageFilesUsageOverview>(
      queryClient,
      projectId,
      storageFilesUsageQueryOptions(
        projectId,
        dateRange,
        interval,
        !includeBreakdown,
      ).queryKey,
    ),
  })
}

export function useProjectStorageDeploymentsChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...storageGaugeChartQueryOptions('deployments', projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectStorageBuildsChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...storageGaugeChartQueryOptions('builds', projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectImageTransformationsUsage(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  includeBreakdown = true,
) {
  const queryClient = useQueryClient()

  return useQuery({
    ...imageTransformationsUsageQueryOptions(
      projectId,
      dateRange,
      interval,
      includeBreakdown,
    ),
    enabled: !!projectId && enabled,
    placeholderData:
      usageOverviewPlaceholderData<StorageImageTransformationsOverview>(
        queryClient,
        projectId,
        imageTransformationsUsageQueryOptions(
          projectId,
          dateRange,
          interval,
          !includeBreakdown,
        ).queryKey,
      ),
  })
}

export function storageBreakdownResourcesQueryOptions(
  projectId: string | null | undefined,
  resourceIds: string[],
) {
  const normalizedIds = normalizeStorageBreakdownResourceIds(resourceIds)

  return queryOptions({
    queryKey: [
      'usage-breakdown',
      'storage-resources',
      'project',
      projectId,
      normalizedIds.join(','),
    ],
    queryFn: () =>
      fetchStorageBreakdownResources(projectId!, normalizedIds),
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

export function useStorageBreakdownResources(
  projectId: string | null | undefined,
  resourceIds: string[],
  enabled = true,
) {
  const normalizedIds = useMemo(
    () => normalizeStorageBreakdownResourceIds(resourceIds),
    [resourceIds],
  )

  return useQuery({
    ...storageBreakdownResourcesQueryOptions(projectId, normalizedIds),
    enabled: enabled && !!projectId && normalizedIds.length > 0,
  })
}

/** Refetch all storage usage charts for a project. */
export function refetchProjectStorageUsageQueries(
  queryClient: QueryClient,
  projectId: string,
) {
  return queryClient.refetchQueries({
    predicate: (query) =>
      Array.isArray(query.queryKey) &&
      query.queryKey.includes('project') &&
      query.queryKey.includes(projectId) &&
      ((query.queryKey[0] === 'usage-gauges' &&
        query.queryKey[1] === 'storage') ||
        (query.queryKey[0] === 'usage-breakdown' &&
          query.queryKey[1] === 'storage-resources')),
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

/** Resolve mixed usage breakdown resource IDs to functions, sites, databases, and buckets. */
export function useUsageResourceBreakdownLookups(
  projectId: string | null | undefined,
  resourceIds: string[],
  enabled = true,
) {
  const shouldFetch = enabled && !!projectId && resourceIds.length > 0
  const { data: computeData } = useComputeBreakdownResources(
    projectId,
    resourceIds,
    shouldFetch,
  )
  const { data: databaseData } = useDatabaseBreakdownResources(
    projectId,
    resourceIds,
    shouldFetch,
  )
  const { data: storageData } = useStorageBreakdownResources(
    projectId,
    resourceIds,
    shouldFetch,
  )
  const { data: tableData } = useTableBreakdownResources(
    projectId,
    resourceIds,
    shouldFetch,
  )

  return {
    computeLookup: computeData?.resources,
    databaseLookup: databaseData?.resources,
    storageLookup: storageData?.resources,
    tableLookup: tableData?.resources,
  }
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

/** Chart-only requests query (no dimension breakdown). */
export function requestsChartOnlyQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return requestsChartOverviewQueryOptions(projectId, dateRange, interval)
}

export function requestsBreakdownQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'requests',
      'breakdown',
      'project',
      projectId,
      dimension,
      rangeKeyPart,
    ],
    queryFn: () =>
      fetchProjectRequestsBreakdown(projectId!, getBounds(), dimension),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function requestsBreakdownDrawerQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'requests',
      'breakdown',
      'drawer',
      'project',
      projectId,
      dimension,
      rangeKeyPart,
      USAGE_BREAKDOWN_DRAWER_LIMIT,
    ],
    queryFn: () =>
      fetchProjectRequestsBreakdown(
        projectId!,
        getBounds(),
        dimension,
        USAGE_BREAKDOWN_DRAWER_LIMIT,
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectRequestsBreakdownDrawer(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension | null | undefined,
  enabled = true,
) {
  return useQuery({
    ...requestsBreakdownDrawerQueryOptions(
      projectId,
      dateRange,
      dimension ?? 'path',
    ),
    enabled: enabled && !!projectId && !!dimension,
  })
}

export function useProjectRequestsChartOnly(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...requestsChartOnlyQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export type RequestsBreakdownQueryEntry = {
  section: RequestsBreakdownSection
  isLoading: boolean
  isError: boolean
  items: UsageBreakdownItem[]
}

/** Fetches all request breakdown dimensions in parallel. */
export function useProjectRequestsBreakdowns(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
): RequestsBreakdownQueryEntry[] {
  const queries = useQueries({
    queries: REQUESTS_BREAKDOWN_SECTIONS.map((section) => ({
      ...requestsBreakdownQueryOptions(projectId, dateRange, section.dimension),
      enabled: enabled && !!projectId,
    })),
  })

  return useMemo(
    () =>
      REQUESTS_BREAKDOWN_SECTIONS.map((section, index) => {
        const query = queries[index]
        return {
          section,
          isLoading: query.isPending && !query.data && !query.isError,
          isError: query.isError,
          items: query.data ?? [],
        }
      }),
    [queries],
  )
}

/** Refetch all requests usage queries for a project (chart + breakdowns). */
export function refetchProjectRequestsUsageQueries(
  queryClient: QueryClient,
  projectId: string,
) {
  return queryClient.refetchQueries({
    predicate: (query) =>
      Array.isArray(query.queryKey) &&
      query.queryKey[0] === 'usage-events' &&
      query.queryKey[1] === 'requests' &&
      query.queryKey.includes('project') &&
      query.queryKey.includes(projectId),
  })
}

export function bandwidthChartOnlyQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return bandwidthOverviewQueryOptions(projectId, dateRange, interval, false)
}

export function useProjectBandwidthChartOnly(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...bandwidthChartOnlyQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function bandwidthBreakdownQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'bandwidth',
      'breakdown',
      'project',
      projectId,
      dimension,
      rangeKeyPart,
    ],
    queryFn: () =>
      fetchProjectBandwidthBreakdown(projectId!, getBounds(), dimension),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function bandwidthBreakdownDrawerQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'bandwidth',
      'breakdown',
      'drawer',
      'project',
      projectId,
      dimension,
      rangeKeyPart,
      USAGE_BREAKDOWN_DRAWER_LIMIT,
    ],
    queryFn: () =>
      fetchProjectBandwidthBreakdown(
        projectId!,
        getBounds(),
        dimension,
        USAGE_BREAKDOWN_DRAWER_LIMIT,
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectBandwidthBreakdownDrawer(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension | null | undefined,
  enabled = true,
) {
  return useQuery({
    ...bandwidthBreakdownDrawerQueryOptions(
      projectId,
      dateRange,
      dimension ?? 'path',
    ),
    enabled: enabled && !!projectId && !!dimension,
  })
}

export type BandwidthBreakdownQueryEntry = {
  section: BandwidthBreakdownSection
  isLoading: boolean
  isError: boolean
  items: UsageBreakdownItem[]
}

/** Fetches all bandwidth breakdown dimensions in parallel. */
export function useProjectBandwidthBreakdowns(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
): BandwidthBreakdownQueryEntry[] {
  const queries = useQueries({
    queries: BANDWIDTH_BREAKDOWN_SECTIONS.map((section) => ({
      ...bandwidthBreakdownQueryOptions(projectId, dateRange, section.dimension),
      enabled: enabled && !!projectId,
    })),
  })

  return useMemo(
    () =>
      BANDWIDTH_BREAKDOWN_SECTIONS.map((section, index) => {
        const query = queries[index]
        return {
          section,
          isLoading: query.isPending && !query.data && !query.isError,
          isError: query.isError,
          items: query.data ?? [],
        }
      }),
    [queries],
  )
}

/** Refetch all bandwidth usage queries for a project (chart + breakdowns). */
export function refetchProjectBandwidthUsageQueries(
  queryClient: QueryClient,
  projectId: string,
) {
  return queryClient.refetchQueries({
    predicate: (query) =>
      Array.isArray(query.queryKey) &&
      query.queryKey[0] === 'usage-events' &&
      query.queryKey[1] === 'bandwidth' &&
      query.queryKey.includes('project') &&
      query.queryKey.includes(projectId),
  })
}

/** @deprecated Use useProjectRequestsChartOnly */
export function useProjectRequestsChartOverview(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useProjectRequestsChartOnly(projectId, dateRange, true, interval)
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
  return getStableUsageChartDateRange()
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

function databaseReadsChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'databases',
      'reads',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectDatabaseReadsOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function databaseWritesChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'databases',
      'writes',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectDatabaseWritesOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function databaseCollectionsChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'databases',
      'collections',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectDatabaseCollectionsOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function databaseDocumentsChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'databases',
      'documents',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectDatabaseDocumentsOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectDatabaseReadsChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...databaseReadsChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectDatabaseWritesChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...databaseWritesChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectDatabaseCollectionsChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...databaseCollectionsChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectDatabaseDocumentsChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...databaseDocumentsChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

function databaseReadsBreakdownQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'databases',
      'reads',
      'breakdown',
      'project',
      projectId,
      dimension,
      rangeKeyPart,
    ],
    queryFn: () =>
      fetchProjectDatabaseReadsBreakdown(projectId!, getBounds(), dimension),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function databaseWritesBreakdownQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'databases',
      'writes',
      'breakdown',
      'project',
      projectId,
      dimension,
      rangeKeyPart,
    ],
    queryFn: () =>
      fetchProjectDatabaseWritesBreakdown(projectId!, getBounds(), dimension),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function databaseReadsBreakdownDrawerQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'databases',
      'reads',
      'breakdown',
      'drawer',
      'project',
      projectId,
      dimension,
      rangeKeyPart,
      USAGE_BREAKDOWN_DRAWER_LIMIT,
    ],
    queryFn: () =>
      fetchProjectDatabaseReadsBreakdown(
        projectId!,
        getBounds(),
        dimension,
        USAGE_BREAKDOWN_DRAWER_LIMIT,
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function databaseWritesBreakdownDrawerQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'databases',
      'writes',
      'breakdown',
      'drawer',
      'project',
      projectId,
      dimension,
      rangeKeyPart,
      USAGE_BREAKDOWN_DRAWER_LIMIT,
    ],
    queryFn: () =>
      fetchProjectDatabaseWritesBreakdown(
        projectId!,
        getBounds(),
        dimension,
        USAGE_BREAKDOWN_DRAWER_LIMIT,
      ),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectDatabaseReadsBreakdownDrawer(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension | null | undefined,
  enabled = true,
) {
  return useQuery({
    ...databaseReadsBreakdownDrawerQueryOptions(
      projectId,
      dateRange,
      dimension ?? 'resourceId',
    ),
    enabled: enabled && !!projectId && !!dimension,
  })
}

export function useProjectDatabaseWritesBreakdownDrawer(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension | null | undefined,
  enabled = true,
) {
  return useQuery({
    ...databaseWritesBreakdownDrawerQueryOptions(
      projectId,
      dateRange,
      dimension ?? 'resourceId',
    ),
    enabled: enabled && !!projectId && !!dimension,
  })
}

export type DatabaseReadsBreakdownQueryEntry = {
  section: DatabaseOperationsBreakdownSection
  isLoading: boolean
  isError: boolean
  items: UsageBreakdownItem[]
}

export function useProjectDatabaseReadsBreakdowns(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
): DatabaseReadsBreakdownQueryEntry[] {
  const queries = useQueries({
    queries: DATABASE_OPERATIONS_BREAKDOWN_SECTIONS.map((section) => ({
      ...databaseReadsBreakdownQueryOptions(
        projectId,
        dateRange,
        section.dimension,
      ),
      enabled: enabled && !!projectId,
    })),
  })

  return useMemo(
    () =>
      DATABASE_OPERATIONS_BREAKDOWN_SECTIONS.map((section, index) => {
        const query = queries[index]
        return {
          section,
          isLoading: query.isPending && !query.data && !query.isError,
          isError: query.isError,
          items: query.data ?? [],
        }
      }),
    [queries],
  )
}

export type DatabaseWritesBreakdownQueryEntry = {
  section: DatabaseOperationsBreakdownSection
  isLoading: boolean
  isError: boolean
  items: UsageBreakdownItem[]
}

export function useProjectDatabaseWritesBreakdowns(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
): DatabaseWritesBreakdownQueryEntry[] {
  const queries = useQueries({
    queries: DATABASE_OPERATIONS_BREAKDOWN_SECTIONS.map((section) => ({
      ...databaseWritesBreakdownQueryOptions(
        projectId,
        dateRange,
        section.dimension,
      ),
      enabled: enabled && !!projectId,
    })),
  })

  return useMemo(
    () =>
      DATABASE_OPERATIONS_BREAKDOWN_SECTIONS.map((section, index) => {
        const query = queries[index]
        return {
          section,
          isLoading: query.isPending && !query.data && !query.isError,
          isError: query.isError,
          items: query.data ?? [],
        }
      }),
    [queries],
  )
}

export function databaseBreakdownResourcesQueryOptions(
  projectId: string | null | undefined,
  resourceIds: string[],
) {
  const normalizedIds = normalizeDatabaseBreakdownResourceIds(resourceIds)

  return queryOptions({
    queryKey: [
      'usage-breakdown',
      'database-resources',
      'project',
      projectId,
      normalizedIds.join(','),
    ],
    queryFn: () =>
      fetchDatabaseBreakdownResources(projectId!, normalizedIds),
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

export function useDatabaseBreakdownResources(
  projectId: string | null | undefined,
  resourceIds: string[],
  enabled = true,
) {
  const normalizedIds = useMemo(
    () => normalizeDatabaseBreakdownResourceIds(resourceIds),
    [resourceIds],
  )

  return useQuery({
    ...databaseBreakdownResourcesQueryOptions(projectId, normalizedIds),
    enabled: enabled && !!projectId && normalizedIds.length > 0,
  })
}

export function tableBreakdownResourcesQueryOptions(
  projectId: string | null | undefined,
  resourceLabels: string[],
) {
  const normalizedLabels = normalizeTableBreakdownResourceLabels(resourceLabels)

  return queryOptions({
    queryKey: [
      'usage-breakdown',
      'table-resources',
      'project',
      projectId,
      normalizedLabels.join(','),
    ],
    queryFn: () =>
      fetchTableBreakdownResources(projectId!, normalizedLabels),
    enabled: !!projectId && normalizedLabels.length > 0,
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

/** Resolve usage breakdown labels to tables/collections (searches all databases when needed). */
export function useTableBreakdownResources(
  projectId: string | null | undefined,
  resourceLabels: string[],
  enabled = true,
) {
  const normalizedLabels = useMemo(
    () => normalizeTableBreakdownResourceLabels(resourceLabels),
    [resourceLabels],
  )

  return useQuery({
    ...tableBreakdownResourcesQueryOptions(projectId, normalizedLabels),
    enabled: enabled && !!projectId && normalizedLabels.length > 0,
  })
}

/** Refetch all database usage charts for a project (reads, writes, collections, documents). */
export function refetchProjectDatabaseUsageQueries(
  queryClient: QueryClient,
  projectId: string,
) {
  return queryClient.refetchQueries({
    predicate: (query) =>
      Array.isArray(query.queryKey) &&
      query.queryKey.includes('project') &&
      query.queryKey.includes(projectId) &&
      ((query.queryKey[0] === 'usage-events' &&
        query.queryKey[1] === 'databases') ||
        (query.queryKey[0] === 'usage-gauges' &&
          query.queryKey[1] === 'databases') ||
        (query.queryKey[0] === 'usage-breakdown' &&
          query.queryKey[1] === 'database-resources')),
  })
}

function realtimeConnectionsChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'realtime',
      'connections',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectRealtimeConnectionsOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function realtimeMessagesChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'realtime',
      'messages',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectRealtimeMessagesOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function realtimeBandwidthChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'realtime',
      'bandwidth',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectRealtimeBandwidthOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectRealtimeConnectionsChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...realtimeConnectionsChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectRealtimeMessagesChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...realtimeMessagesChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectRealtimeBandwidthChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...realtimeBandwidthChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

/** Refetch all realtime usage charts for a project. */
export function refetchProjectRealtimeUsageQueries(
  queryClient: QueryClient,
  projectId: string,
) {
  return queryClient.refetchQueries({
    predicate: (query) =>
      Array.isArray(query.queryKey) &&
      query.queryKey.includes('project') &&
      query.queryKey.includes(projectId) &&
      query.queryKey[0] === 'usage-events' &&
      query.queryKey[1] === 'realtime',
  })
}

function authMauChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'auth',
      'mau',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectAuthMauOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function authOtpChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'auth',
      'otp',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectAuthOtpOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function authSignupsChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'auth',
      'signups',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectAuthSignupsOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectAuthMauChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...authMauChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectAuthOtpChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...authOtpChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectAuthSignupsChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...authSignupsChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

/** Refetch all auth usage charts for a project. */
export function refetchProjectAuthUsageQueries(
  queryClient: QueryClient,
  projectId: string,
) {
  return queryClient.refetchQueries({
    predicate: (query) =>
      Array.isArray(query.queryKey) &&
      query.queryKey.includes('project') &&
      query.queryKey.includes(projectId) &&
      ((query.queryKey[0] === 'usage-events' && query.queryKey[1] === 'auth') ||
        (query.queryKey[0] === 'usage-gauges' && query.queryKey[1] === 'auth')),
  })
}

function avatarsScreenshotsChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'avatars',
      'screenshots',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectAvatarsScreenshotsOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectAvatarsScreenshotsChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...avatarsScreenshotsChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

/** Refetch avatars usage charts for a project. */
export function refetchProjectAvatarsUsageQueries(
  queryClient: QueryClient,
  projectId: string,
) {
  return queryClient.refetchQueries({
    predicate: (query) =>
      Array.isArray(query.queryKey) &&
      query.queryKey.includes('project') &&
      query.queryKey.includes(projectId) &&
      query.queryKey[0] === 'usage-events' &&
      query.queryKey[1] === 'avatars',
  })
}

function messagingMessagesChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'messaging',
      'messages',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectMessagingMessagesOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function messagingSmsChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'messaging',
      'sms',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectMessagingSmsOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function messagingTopicsChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'messaging',
      'topics',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectMessagingTopicsOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectMessagingMessagesChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...messagingMessagesChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectMessagingSmsChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...messagingSmsChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectMessagingTopicsChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...messagingTopicsChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

/** Refetch messaging usage charts for a project. */
export function refetchProjectMessagingUsageQueries(
  queryClient: QueryClient,
  projectId: string,
) {
  return queryClient.refetchQueries({
    predicate: (query) =>
      Array.isArray(query.queryKey) &&
      query.queryKey.includes('project') &&
      query.queryKey.includes(projectId) &&
      ((query.queryKey[0] === 'usage-events' &&
        query.queryKey[1] === 'messaging') ||
        (query.queryKey[0] === 'usage-gauges' &&
          query.queryKey[1] === 'messaging')),
  })
}

function webhooksEventsSentChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'webhooks',
      'events-sent',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectWebhooksEventsSentOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function webhooksEventsFailedChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'webhooks',
      'events-failed',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectWebhooksEventsFailedOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

function webhooksCountChartQueryOptions(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } = normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'webhooks',
      'count',
      'chart',
      'project',
      projectId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchProjectWebhooksCountOverview(projectId!, getBounds(), interval),
    enabled: !!projectId,
    ...usageEventsQueryOptionsBase,
    placeholderData: keepPreviousUsageChartDataForProject(projectId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageEventsQueryOptionsBase.refetchOnMount,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectWebhooksEventsSentChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...webhooksEventsSentChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectWebhooksEventsFailedChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...webhooksEventsFailedChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

export function useProjectWebhooksCountChart(
  projectId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...webhooksCountChartQueryOptions(projectId, dateRange, interval),
    enabled: !!projectId && enabled,
  })
}

/** Refetch webhooks usage charts for a project. */
export function refetchProjectWebhooksUsageQueries(
  queryClient: QueryClient,
  projectId: string,
) {
  return queryClient.refetchQueries({
    predicate: (query) =>
      Array.isArray(query.queryKey) &&
      query.queryKey.includes('project') &&
      query.queryKey.includes(projectId) &&
      ((query.queryKey[0] === 'usage-events' &&
        query.queryKey[1] === 'webhooks') ||
        (query.queryKey[0] === 'usage-gauges' &&
          query.queryKey[1] === 'webhooks')),
  })
}

/** Refetch compute usage charts (executions, GB-hours) for a project. */
export function refetchProjectComputeUsageQueries(
  queryClient: QueryClient,
  projectId: string,
) {
  const computeScopes = new Set([
    'executions',
    'function-executions',
    'site-executions',
    'gb-hours',
    'function-gb-hours',
    'site-gb-hours',
  ])

  return queryClient.refetchQueries({
    predicate: (query) =>
      Array.isArray(query.queryKey) &&
      query.queryKey.includes('project') &&
      query.queryKey.includes(projectId) &&
      ((query.queryKey[0] === 'usage-events' &&
        typeof query.queryKey[1] === 'string' &&
        computeScopes.has(query.queryKey[1])) ||
        (query.queryKey[0] === 'usage-breakdown' &&
          query.queryKey[1] === 'compute-resources')),
  })
}

export type {
  ComputeBreakdownResourceMap,
} from '@/lib/usage/resolve-compute-breakdown-resources'

export type {
  StorageBreakdownResourceMap,
} from '@/lib/usage/resolve-storage-breakdown-resources'

export type {
  DatabaseBreakdownResourceMap,
} from '@/lib/usage/resolve-database-breakdown-resources'

export type {
  ProjectBandwidthOverview,
  ProjectExecutionsOverview,
  ProjectGbHoursOverview,
  ProjectStorageOverview,
  StorageFilesUsageOverview,
  StorageImageTransformationsOverview,
  StorageUsageChartOverview,
  ProjectRequestsOverview,
  ProjectRequestsChartOverview,
  ProjectBandwidthOverview as ProjectBandwidthChartOverview,
  ProjectBandwidthOverview as ProjectBandwidthTopConsumersOverview,
  ProjectRequestsOverview as ProjectRequestsTopEndpointsOverview,
  DatabaseUsageChartOverview,
  RealtimeBandwidthOverview,
  RealtimeUsageChartOverview,
  AuthUsageChartOverview,
  AvatarsUsageChartOverview,
  MessagingUsageChartOverview,
  WebhooksUsageChartOverview,
}

export type { UsageChartInterval } from '@/lib/usage/chart-interval'
export { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
