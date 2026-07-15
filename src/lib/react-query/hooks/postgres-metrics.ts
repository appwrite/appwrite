import { useCallback } from 'react'
import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { requireOperationalDatabase } from '@/lib/databases/dedicated-database-write-lock'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { executePostgresDatabaseSql } from './postgres-databases'
import {
  buildPostgresCancelBackendSql,
  buildPostgresTerminateBackendSql,
  POSTGRES_TERMINATE_IDLE_IN_TRANSACTION_SQL,
} from '@/lib/postgres-connection-actions'
import {
  POSTGRES_ACTIVE_CONNECTIONS_SQL,
  POSTGRES_METRICS_CONNECTION_APPS_SQL,
  POSTGRES_METRICS_CONNECTION_STATES_SQL,
  POSTGRES_METRICS_SNAPSHOT_SQL,
  POSTGRES_METRICS_TABLE_ACTIVITY_SQL,
} from '@/lib/postgres-metrics-sql'
import {
  parsePostgresActiveConnections,
  parsePostgresConnectionApps,
  parsePostgresConnectionStates,
  parsePostgresMetricsSnapshot,
  parsePostgresTableActivity,
  type PostgresActiveConnectionRow,
  type PostgresConnectionAppRow,
  type PostgresConnectionStateRow,
  type PostgresMetricsSnapshot,
  type PostgresTableActivityRow,
} from '@/lib/postgres-metrics'
import { DEFAULT_STALE_TIME } from './constants'

const METRICS_POLL_INTERVAL_MS = 60_000
const ACTIVE_CONNECTIONS_POLL_INTERVAL_MS = 30_000

export async function fetchPostgresActiveConnections(
  projectId: string,
  databaseId: string,
): Promise<PostgresActiveConnectionRow[]> {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    POSTGRES_ACTIVE_CONNECTIONS_SQL,
    30,
  )
  return parsePostgresActiveConnections(execution)
}

export async function fetchPostgresMetricsSnapshot(
  projectId: string,
  databaseId: string,
): Promise<PostgresMetricsSnapshot | null> {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    POSTGRES_METRICS_SNAPSHOT_SQL,
    30,
  )
  return parsePostgresMetricsSnapshot(execution)
}

export async function fetchPostgresConnectionStates(
  projectId: string,
  databaseId: string,
): Promise<PostgresConnectionStateRow[]> {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    POSTGRES_METRICS_CONNECTION_STATES_SQL,
    30,
  )
  return parsePostgresConnectionStates(execution)
}

export async function fetchPostgresConnectionApps(
  projectId: string,
  databaseId: string,
): Promise<PostgresConnectionAppRow[]> {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    POSTGRES_METRICS_CONNECTION_APPS_SQL,
    30,
  )
  return parsePostgresConnectionApps(execution)
}

export async function fetchPostgresTableActivity(
  projectId: string,
  databaseId: string,
): Promise<PostgresTableActivityRow[]> {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    POSTGRES_METRICS_TABLE_ACTIVITY_SQL,
    30,
  )
  return parsePostgresTableActivity(execution)
}

export function postgresMetricsSnapshotQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['postgres', 'metrics', 'snapshot', projectId, databaseId],
    queryFn: () => fetchPostgresMetricsSnapshot(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: 30_000,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    retry: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresConnectionStatesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['postgres', 'metrics', 'connection-states', projectId, databaseId],
    queryFn: () => fetchPostgresConnectionStates(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    retry: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresConnectionAppsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['postgres', 'metrics', 'connection-apps', projectId, databaseId],
    queryFn: () => fetchPostgresConnectionApps(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    retry: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresActiveConnectionsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['postgres', 'active-connections', projectId, databaseId],
    queryFn: () => fetchPostgresActiveConnections(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: 15_000,
    refetchInterval: ACTIVE_CONNECTIONS_POLL_INTERVAL_MS,
    refetchOnMount: false,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    retry: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresTableActivityQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['postgres', 'metrics', 'tables', projectId, databaseId],
    queryFn: () => fetchPostgresTableActivity(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    retry: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function usePostgresMetricsSnapshot(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const query = useQuery(postgresMetricsSnapshotQueryOptions(projectId, databaseId))
  return {
    snapshot: query.data ?? null,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    error: query.error,
    refetch: query.refetch,
  }
}

export function usePostgresConnectionStates(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const query = useQuery(
    postgresConnectionStatesQueryOptions(projectId, databaseId),
  )
  return {
    states: query.data ?? [],
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  }
}

export function usePostgresConnectionApps(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const query = useQuery(
    postgresConnectionAppsQueryOptions(projectId, databaseId),
  )
  return {
    apps: query.data ?? [],
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  }
}

export function usePostgresActiveConnections(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const query = useQuery(
    postgresActiveConnectionsQueryOptions(projectId, databaseId),
  )
  return {
    connections: query.data ?? [],
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    error: query.error,
    refetch: query.refetch,
  }
}

export function usePostgresTableActivity(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const query = useQuery(postgresTableActivityQueryOptions(projectId, databaseId))
  return {
    tables: query.data ?? [],
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  }
}

/**
 * Polls the PostgreSQL metrics snapshot while the monitor view is open.
 * Used for overview KPIs and live health signals (not time-series charts).
 */
export function usePostgresMetricsSampling(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  options?: { enabled?: boolean; pollIntervalMs?: number },
) {
  const enabled = options?.enabled ?? true
  const pollIntervalMs = options?.pollIntervalMs ?? METRICS_POLL_INTERVAL_MS

  const query = useQuery({
    ...postgresMetricsSnapshotQueryOptions(projectId, databaseId),
    enabled: enabled && !!projectId && !!databaseId,
    refetchInterval: enabled ? pollIntervalMs : false,
  })

  const refresh = useCallback(() => query.refetch(), [query.refetch])

  return {
    snapshot: query.data ?? null,
    lastRecordedAt: query.dataUpdatedAt > 0 ? query.dataUpdatedAt : null,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    error: query.error,
    refresh,
  }
}

function postgresActiveConnectionsQueryKey(
  projectId: string,
  databaseId: string,
) {
  return ['postgres', 'active-connections', projectId, databaseId] as const
}

async function refreshPostgresActiveConnections(
  queryClient: ReturnType<typeof useQueryClient>,
  projectId: string,
  databaseId: string,
) {
  await queryClient.refetchQueries({
    queryKey: postgresActiveConnectionsQueryKey(projectId, databaseId),
  })
}

export function useCancelPostgresBackend(
  projectId: string,
  databaseId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (pid: number) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return executePostgresDatabaseSql(
        projectId,
        databaseId,
        buildPostgresCancelBackendSql(pid),
      )
    },
    onSuccess: async () => {
      await refreshPostgresActiveConnections(queryClient, projectId, databaseId)
      toast.success('Query canceled')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) ?? 'Failed to cancel query')
    },
  })
}

export function useTerminatePostgresBackend(
  projectId: string,
  databaseId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (pid: number) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return executePostgresDatabaseSql(
        projectId,
        databaseId,
        buildPostgresTerminateBackendSql(pid),
      )
    },
    onSuccess: async () => {
      await refreshPostgresActiveConnections(queryClient, projectId, databaseId)
      toast.success('Connection terminated')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) ?? 'Failed to terminate connection')
    },
  })
}

export function useTerminatePostgresIdleInTransaction(
  projectId: string,
  databaseId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return executePostgresDatabaseSql(
        projectId,
        databaseId,
        POSTGRES_TERMINATE_IDLE_IN_TRANSACTION_SQL,
      )
    },
    onSuccess: async () => {
      await refreshPostgresActiveConnections(queryClient, projectId, databaseId)
      toast.success('Idle in transaction connections terminated')
    },
    onError: (error: Error) => {
      toast.error(
        getErrorMessage(error) ??
          'Failed to terminate idle in transaction connections',
      )
    },
  })
}
