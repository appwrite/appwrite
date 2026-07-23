/**
 * React Query hooks for dedicated database instance metrics and per-database
 * serverless read/write operations (monitor pages).
 */

import {
  queryOptions,
  useQuery,
  useQueries,
  type Query,
  type QueryClient,
  type QueryKey,
} from '@tanstack/react-query'
import { useCallback, useEffect, useMemo } from 'react'
import type { DateRange } from 'react-day-picker'
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
import { getUsageChartLatestValue } from '@/lib/usage/database-usage'
import { DEFAULT_STALE_TIME } from './constants'
import {
  DEDICATED_DATABASE_USAGE_RESOURCE_TYPE,
  fetchDedicatedDatabaseConnectionsOverview,
  fetchDedicatedDatabaseCpuOverview,
  fetchDedicatedDatabaseIopsReadOverview,
  fetchDedicatedDatabaseIopsWriteOverview,
  fetchDedicatedDatabaseMemoryOverview,
  fetchDedicatedDatabaseQpsOverview,
  fetchDedicatedDatabaseStorageOverview,
  type DedicatedDatabaseUsageChartOverview,
} from '@/lib/usage/dedicated-databases-usage'
import {
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
import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import { areUsageBreakdownQueriesEnabled } from '@/lib/debug-overrides'

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

const usageQueryOptionsBase = {
  staleTime: DEFAULT_STALE_TIME,
  retry: false,
  refetchOnMount: false as const,
  refetchOnWindowFocus: false as const,
  refetchOnReconnect: false as const,
  meta: {
    skipInitialLoader: true,
  },
}

/** Keep chart data when date/interval change, but not when switching project/database/node. */
function keepPreviousDedicatedChartData<T>(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  ordinal?: number,
) {
  return (
    previousData: T | undefined,
    previousQuery: Query<T> | undefined,
  ): T | undefined => {
    if (previousData === undefined || !previousQuery || !projectId || !databaseId) {
      return undefined
    }
    const key = previousQuery.queryKey as QueryKey
    const projectIndex = key.indexOf('project')
    const databaseIndex = key.indexOf('database')
    const ordinalIndex = key.indexOf('ordinal')
    const expectedOrdinal = ordinal ?? 'all'
    if (
      projectIndex === -1 ||
      databaseIndex === -1 ||
      key[projectIndex + 1] !== projectId ||
      key[databaseIndex + 1] !== databaseId
    ) {
      return undefined
    }
    if (ordinalIndex === -1 || key[ordinalIndex + 1] !== expectedOrdinal) {
      return undefined
    }
    return previousData
  }
}

function dedicatedMetricFetchOptions(
  databaseId: string,
  ordinal?: number,
): {
  resourceId: string
  resourceType: typeof DEDICATED_DATABASE_USAGE_RESOURCE_TYPE
  includeBreakdown: false
  ordinal?: number
} {
  return {
    resourceId: databaseId,
    resourceType: DEDICATED_DATABASE_USAGE_RESOURCE_TYPE,
    includeBreakdown: false,
    ...(ordinal !== undefined ? { ordinal } : {}),
  }
}

function dedicatedDatabaseMetricChartQueryOptions(
  metricKey: string,
  fetchFn: (
    projectId: string,
    dateRange: DateRange | undefined,
    interval: UsageChartInterval,
    options?: {
      resourceId: string
      includeBreakdown?: boolean
      ordinal?: number
    },
  ) => Promise<DedicatedDatabaseUsageChartOverview>,
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } =
    normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-gauges',
      'dedicated-databases',
      metricKey,
      'chart',
      'project',
      projectId,
      'database',
      databaseId,
      'ordinal',
      ordinal ?? 'all',
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchFn(
        projectId!,
        getBounds(),
        interval,
        dedicatedMetricFetchOptions(databaseId!, ordinal),
      ),
    enabled: !!projectId && !!databaseId,
    ...usageQueryOptionsBase,
    placeholderData: keepPreviousDedicatedChartData(
      projectId,
      databaseId,
      ordinal,
    ),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageQueryOptionsBase.refetchOnMount,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function dedicatedDatabaseStorageChartQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return dedicatedDatabaseMetricChartQueryOptions(
    'storage',
    fetchDedicatedDatabaseStorageOverview,
    projectId,
    databaseId,
    dateRange,
    interval,
    ordinal,
  )
}

export function dedicatedDatabaseConnectionsChartQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return dedicatedDatabaseMetricChartQueryOptions(
    'connections',
    fetchDedicatedDatabaseConnectionsOverview,
    projectId,
    databaseId,
    dateRange,
    interval,
    ordinal,
  )
}

export function dedicatedDatabaseCpuChartQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return dedicatedDatabaseMetricChartQueryOptions(
    'cpu',
    fetchDedicatedDatabaseCpuOverview,
    projectId,
    databaseId,
    dateRange,
    interval,
    ordinal,
  )
}

export function dedicatedDatabaseMemoryChartQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return dedicatedDatabaseMetricChartQueryOptions(
    'memory',
    fetchDedicatedDatabaseMemoryOverview,
    projectId,
    databaseId,
    dateRange,
    interval,
    ordinal,
  )
}

export function dedicatedDatabaseQpsChartQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return dedicatedDatabaseMetricChartQueryOptions(
    'qps',
    fetchDedicatedDatabaseQpsOverview,
    projectId,
    databaseId,
    dateRange,
    interval,
    ordinal,
  )
}

export function dedicatedDatabaseIopsReadChartQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return dedicatedDatabaseMetricChartQueryOptions(
    'iops-read',
    fetchDedicatedDatabaseIopsReadOverview,
    projectId,
    databaseId,
    dateRange,
    interval,
    ordinal,
  )
}

export function dedicatedDatabaseIopsWriteChartQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return dedicatedDatabaseMetricChartQueryOptions(
    'iops-write',
    fetchDedicatedDatabaseIopsWriteOverview,
    projectId,
    databaseId,
    dateRange,
    interval,
    ordinal,
  )
}

export function useDedicatedDatabaseStorageChart(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return useQuery({
    ...dedicatedDatabaseStorageChartQueryOptions(
      projectId,
      databaseId,
      dateRange,
      interval,
      ordinal,
    ),
    enabled: !!projectId && !!databaseId && enabled,
  })
}

export function useDedicatedDatabaseConnectionsChart(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return useQuery({
    ...dedicatedDatabaseConnectionsChartQueryOptions(
      projectId,
      databaseId,
      dateRange,
      interval,
      ordinal,
    ),
    enabled: !!projectId && !!databaseId && enabled,
  })
}

export function useDedicatedDatabaseCpuChart(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return useQuery({
    ...dedicatedDatabaseCpuChartQueryOptions(
      projectId,
      databaseId,
      dateRange,
      interval,
      ordinal,
    ),
    enabled: !!projectId && !!databaseId && enabled,
  })
}

export function useDedicatedDatabaseMemoryChart(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return useQuery({
    ...dedicatedDatabaseMemoryChartQueryOptions(
      projectId,
      databaseId,
      dateRange,
      interval,
      ordinal,
    ),
    enabled: !!projectId && !!databaseId && enabled,
  })
}

export function useDedicatedDatabaseQpsChart(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return useQuery({
    ...dedicatedDatabaseQpsChartQueryOptions(
      projectId,
      databaseId,
      dateRange,
      interval,
      ordinal,
    ),
    enabled: !!projectId && !!databaseId && enabled,
  })
}

export function useDedicatedDatabaseIopsReadChart(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return useQuery({
    ...dedicatedDatabaseIopsReadChartQueryOptions(
      projectId,
      databaseId,
      dateRange,
      interval,
      ordinal,
    ),
    enabled: !!projectId && !!databaseId && enabled,
  })
}

export function useDedicatedDatabaseIopsWriteChart(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  return useQuery({
    ...dedicatedDatabaseIopsWriteChartQueryOptions(
      projectId,
      databaseId,
      dateRange,
      interval,
      ordinal,
    ),
    enabled: !!projectId && !!databaseId && enabled,
  })
}

export function useDedicatedDatabaseMonitorMetrics(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  ordinal?: number,
) {
  const storage = useDedicatedDatabaseStorageChart(
    projectId,
    databaseId,
    dateRange,
    enabled,
    interval,
    ordinal,
  )
  const connections = useDedicatedDatabaseConnectionsChart(
    projectId,
    databaseId,
    dateRange,
    enabled,
    interval,
    ordinal,
  )
  const cpu = useDedicatedDatabaseCpuChart(
    projectId,
    databaseId,
    dateRange,
    enabled,
    interval,
    ordinal,
  )
  const memory = useDedicatedDatabaseMemoryChart(
    projectId,
    databaseId,
    dateRange,
    enabled,
    interval,
    ordinal,
  )
  const qps = useDedicatedDatabaseQpsChart(
    projectId,
    databaseId,
    dateRange,
    enabled,
    interval,
    ordinal,
  )
  const iopsRead = useDedicatedDatabaseIopsReadChart(
    projectId,
    databaseId,
    dateRange,
    enabled,
    interval,
    ordinal,
  )
  const iopsWrite = useDedicatedDatabaseIopsWriteChart(
    projectId,
    databaseId,
    dateRange,
    enabled,
    interval,
    ordinal,
  )

  const refetchAll = useCallback(async () => {
    await Promise.all([
      storage.refetch(),
      connections.refetch(),
      cpu.refetch(),
      memory.refetch(),
      qps.refetch(),
      iopsRead.refetch(),
      iopsWrite.refetch(),
    ])
  }, [
    storage.refetch,
    connections.refetch,
    cpu.refetch,
    memory.refetch,
    qps.refetch,
    iopsRead.refetch,
    iopsWrite.refetch,
  ])

  return {
    storage,
    connections,
    cpu,
    memory,
    qps,
    iopsRead,
    iopsWrite,
    refetchAll,
  }
}

function databaseOperationsChartQueryOptions(
  operation: 'reads' | 'writes',
  fetchFn: (
    projectId: string,
    dateRange: DateRange | undefined,
    interval: UsageChartInterval,
    options?: { resourceId: string; includeBreakdown?: boolean },
  ) => Promise<DatabaseUsageChartOverview>,
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  const { rangeKeyPart, getBounds, refetchOnMountRolling } =
    normalizeDateRangeKey(dateRange)

  return queryOptions({
    queryKey: [
      'usage-events',
      'databases',
      operation,
      'chart',
      'project',
      projectId,
      'database',
      databaseId,
      rangeKeyPart,
      interval,
    ],
    queryFn: () =>
      fetchFn(projectId!, getBounds(), interval, {
        resourceId: databaseId!,
        includeBreakdown: false,
      }),
    enabled: !!projectId && !!databaseId,
    ...usageQueryOptionsBase,
    placeholderData: keepPreviousDedicatedChartData(projectId, databaseId),
    refetchOnMount: refetchOnMountRolling
      ? 'always'
      : usageQueryOptionsBase.refetchOnMount,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function databaseReadsForDatabaseChartQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return databaseOperationsChartQueryOptions(
    'reads',
    fetchProjectDatabaseReadsOverview,
    projectId,
    databaseId,
    dateRange,
    interval,
  )
}

export function databaseWritesForDatabaseChartQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return databaseOperationsChartQueryOptions(
    'writes',
    fetchProjectDatabaseWritesOverview,
    projectId,
    databaseId,
    dateRange,
    interval,
  )
}

export function useDatabaseReadsForDatabaseChart(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...databaseReadsForDatabaseChartQueryOptions(
      projectId,
      databaseId,
      dateRange,
      interval,
    ),
    enabled: !!projectId && !!databaseId && enabled,
  })
}

export function useDatabaseWritesForDatabaseChart(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
) {
  return useQuery({
    ...databaseWritesForDatabaseChartQueryOptions(
      projectId,
      databaseId,
      dateRange,
      interval,
    ),
    enabled: !!projectId && !!databaseId && enabled,
  })
}

export type DatabaseOperationsForDatabaseBreakdownEntry = {
  section: DatabaseOperationsBreakdownSection
  items: Awaited<ReturnType<typeof fetchProjectDatabaseReadsBreakdown>>
  isLoading: boolean
  isError: boolean
  error: unknown
}

export function useDatabaseReadsForDatabaseBreakdowns(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
) {
  const showBreakdown = enabled && areUsageBreakdownQueriesEnabled()
  const { rangeKeyPart, getBounds } = normalizeDateRangeKey(dateRange)

  const queries = useQueries({
    queries: DATABASE_OPERATIONS_BREAKDOWN_SECTIONS.map((section) => ({
      queryKey: [
        'usage-events',
        'databases',
        'reads',
        'breakdown',
        'project',
        projectId,
        'database',
        databaseId,
        section.dimension,
        rangeKeyPart,
      ],
      queryFn: () =>
        fetchProjectDatabaseReadsBreakdown(
          projectId!,
          getBounds(),
          section.dimension,
          OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
          undefined,
          databaseId!,
        ),
      enabled: !!projectId && !!databaseId && showBreakdown,
      ...usageQueryOptionsBase,
      gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
    })),
  })

  return DATABASE_OPERATIONS_BREAKDOWN_SECTIONS.map((section, index) => {
    const query = queries[index]!
    return {
      section,
      items: query.data ?? [],
      isLoading: query.isLoading,
      isError: query.isError,
      error: query.error,
    } satisfies DatabaseOperationsForDatabaseBreakdownEntry
  })
}

export function useDatabaseWritesForDatabaseBreakdowns(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  dateRange: DateRange | undefined,
  enabled = true,
) {
  const showBreakdown = enabled && areUsageBreakdownQueriesEnabled()
  const { rangeKeyPart, getBounds } = normalizeDateRangeKey(dateRange)

  const queries = useQueries({
    queries: DATABASE_OPERATIONS_BREAKDOWN_SECTIONS.map((section) => ({
      queryKey: [
        'usage-events',
        'databases',
        'writes',
        'breakdown',
        'project',
        projectId,
        'database',
        databaseId,
        section.dimension,
        rangeKeyPart,
      ],
      queryFn: () =>
        fetchProjectDatabaseWritesBreakdown(
          projectId!,
          getBounds(),
          section.dimension,
          OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
          undefined,
          databaseId!,
        ),
      enabled: !!projectId && !!databaseId && showBreakdown,
      ...usageQueryOptionsBase,
      gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
    })),
  })

  return DATABASE_OPERATIONS_BREAKDOWN_SECTIONS.map((section, index) => {
    const query = queries[index]!
    return {
      section,
      items: query.data ?? [],
      isLoading: query.isLoading,
      isError: query.isError,
      error: query.error,
    } satisfies DatabaseOperationsForDatabaseBreakdownEntry
  })
}

export async function refetchDedicatedDatabaseMonitorQueries(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
) {
  await Promise.all([
    queryClient.refetchQueries({
      queryKey: [
        'usage-gauges',
        'dedicated-databases',
      ],
      predicate: (query) =>
        query.queryKey.includes(projectId) &&
        query.queryKey.includes(databaseId),
    }),
    queryClient.refetchQueries({
      queryKey: ['usage-events', 'databases'],
      predicate: (query) =>
        query.queryKey.includes(projectId) &&
        query.queryKey.includes(databaseId),
    }),
  ])
}

/** Refetch monitor charts when the header refresh control bumps `chartTick`. */
export function useRefetchOnMonitorChartTick(
  chartTick: number,
  refetch: () => void | Promise<unknown>,
) {
  useEffect(() => {
    if (chartTick <= 0) return
    void refetch()
  }, [chartTick, refetch])
}

/** Per-node CPU/memory for dedicated database list cards (primary + replicas). */
export type DedicatedDatabaseCardNodeMetrics = {
  cpu: number | null
  memory: number | null
}

/**
 * Card-sized dedicated metrics: latest CPU/memory per ordinal and connections.
 * Uses the same usage.listGauges queries as the database monitor page (last 24h).
 *
 * Ordinal filtering matches Monitor: omit `ordinal` for single-node instances
 * (gauges are not tagged until HA is enabled); pass 0..N when replicas exist.
 */
export function useDedicatedDatabaseCardMetrics(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  replicaCount: number,
  enabled = true,
) {
  const dateRange = useMemo(() => getStableUsageChartDateRange(), [])
  const nodeCount = 1 + Math.max(0, Math.floor(replicaCount))
  const canFetch = Boolean(projectId && databaseId && enabled)
  // Match Monitor: only scope by ordinal when the cluster has replicas.
  const hasReplicas = replicaCount > 0
  const connectionsOrdinal = hasReplicas ? 0 : undefined

  const connectionsQuery = useDedicatedDatabaseConnectionsChart(
    projectId,
    databaseId,
    dateRange,
    canFetch,
    DEFAULT_USAGE_CHART_INTERVAL,
    connectionsOrdinal,
  )

  const cpuQueries = useQueries({
    queries: Array.from({ length: nodeCount }, (_, index) => ({
      ...dedicatedDatabaseCpuChartQueryOptions(
        projectId,
        databaseId,
        dateRange,
        DEFAULT_USAGE_CHART_INTERVAL,
        hasReplicas ? index : undefined,
      ),
      enabled: canFetch,
    })),
  })

  const memoryQueries = useQueries({
    queries: Array.from({ length: nodeCount }, (_, index) => ({
      ...dedicatedDatabaseMemoryChartQueryOptions(
        projectId,
        databaseId,
        dateRange,
        DEFAULT_USAGE_CHART_INTERVAL,
        hasReplicas ? index : undefined,
      ),
      enabled: canFetch,
    })),
  })

  const nodeMetrics: DedicatedDatabaseCardNodeMetrics[] = Array.from(
    { length: nodeCount },
    (_, index) => {
      const cpuPoints = cpuQueries[index]?.data?.chartPoints
      const memoryPoints = memoryQueries[index]?.data?.chartPoints
      return {
        cpu:
          cpuPoints && cpuPoints.length > 0
            ? getUsageChartLatestValue(cpuPoints)
            : null,
        memory:
          memoryPoints && memoryPoints.length > 0
            ? getUsageChartLatestValue(memoryPoints)
            : null,
      }
    },
  )

  const connectionPoints = connectionsQuery.data?.chartPoints
  const connections =
    connectionPoints && connectionPoints.length > 0
      ? Math.round(getUsageChartLatestValue(connectionPoints))
      : null

  const refetch = useCallback(async () => {
    await Promise.all([
      connectionsQuery.refetch(),
      ...cpuQueries.map((query) => query.refetch()),
      ...memoryQueries.map((query) => query.refetch()),
    ])
  }, [connectionsQuery, cpuQueries, memoryQueries])

  return {
    nodeMetrics,
    connections,
    isLoading:
      canFetch &&
      (connectionsQuery.isLoading ||
        cpuQueries.some((query) => query.isLoading) ||
        memoryQueries.some((query) => query.isLoading)),
    isFetching:
      canFetch &&
      (connectionsQuery.isFetching ||
        cpuQueries.some((query) => query.isFetching) ||
        memoryQueries.some((query) => query.isFetching)),
    refetch,
  }
}

export type { UsageEventBreakdownDimension, DatabaseOperationsBreakdownSection }
