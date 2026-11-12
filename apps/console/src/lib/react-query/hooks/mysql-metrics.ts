import { useCallback } from 'react'
import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { requireOperationalDatabase } from '@/lib/databases/dedicated-database-write-lock'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { executeMysqlDatabaseSql } from './mysql-databases'
import {
  buildMysqlCancelBackendSql,
  buildMysqlTerminateBackendSql,
  MYSQL_TERMINATE_IDLE_IN_TRANSACTION_SQL,
} from '@/lib/mysql-connection-actions'
import {
  MYSQL_ACTIVE_CONNECTIONS_SQL,
  MYSQL_METRICS_CONNECTION_APPS_SQL,
  MYSQL_METRICS_CONNECTION_STATES_SQL,
  MYSQL_METRICS_SNAPSHOT_SQL,
  MYSQL_METRICS_TABLE_ACTIVITY_SQL,
} from '@/lib/mysql-metrics-sql'
import {
  parseMysqlActiveConnections,
  parseMysqlConnectionApps,
  parseMysqlConnectionStates,
  parseMysqlMetricsSnapshot,
  parseMysqlTableActivity,
  type MysqlActiveConnectionRow,
  type MysqlConnectionAppRow,
  type MysqlConnectionStateRow,
  type MysqlMetricsSnapshot,
  type MysqlTableActivityRow,
} from '@/lib/mysql-metrics'
import { DEFAULT_STALE_TIME } from './constants'

const METRICS_POLL_INTERVAL_MS = 60_000
const ACTIVE_CONNECTIONS_POLL_INTERVAL_MS = 30_000

export async function fetchMysqlActiveConnections(
  projectId: string,
  databaseId: string,
): Promise<MysqlActiveConnectionRow[]> {
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    MYSQL_ACTIVE_CONNECTIONS_SQL,
    30,
  )
  return parseMysqlActiveConnections(execution)
}

export async function fetchMysqlMetricsSnapshot(
  projectId: string,
  databaseId: string,
): Promise<MysqlMetricsSnapshot | null> {
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    MYSQL_METRICS_SNAPSHOT_SQL,
    30,
  )
  return parseMysqlMetricsSnapshot(execution)
}

export async function fetchMysqlConnectionStates(
  projectId: string,
  databaseId: string,
): Promise<MysqlConnectionStateRow[]> {
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    MYSQL_METRICS_CONNECTION_STATES_SQL,
    30,
  )
  return parseMysqlConnectionStates(execution)
}

export async function fetchMysqlConnectionApps(
  projectId: string,
  databaseId: string,
): Promise<MysqlConnectionAppRow[]> {
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    MYSQL_METRICS_CONNECTION_APPS_SQL,
    30,
  )
  return parseMysqlConnectionApps(execution)
}

export async function fetchMysqlTableActivity(
  projectId: string,
  databaseId: string,
): Promise<MysqlTableActivityRow[]> {
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    MYSQL_METRICS_TABLE_ACTIVITY_SQL,
    30,
  )
  return parseMysqlTableActivity(execution)
}

export function mysqlMetricsSnapshotQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['mysql', 'metrics', 'snapshot', projectId, databaseId],
    queryFn: () => fetchMysqlMetricsSnapshot(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: 30_000,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    retry: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlConnectionStatesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['mysql', 'metrics', 'connection-states', projectId, databaseId],
    queryFn: () => fetchMysqlConnectionStates(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    retry: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlConnectionAppsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['mysql', 'metrics', 'connection-apps', projectId, databaseId],
    queryFn: () => fetchMysqlConnectionApps(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    retry: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlActiveConnectionsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['mysql', 'active-connections', projectId, databaseId],
    queryFn: () => fetchMysqlActiveConnections(projectId!, databaseId!),
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

export function mysqlTableActivityQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['mysql', 'metrics', 'tables', projectId, databaseId],
    queryFn: () => fetchMysqlTableActivity(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    retry: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function useMysqlMetricsSnapshot(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const query = useQuery(mysqlMetricsSnapshotQueryOptions(projectId, databaseId))
  return {
    snapshot: query.data ?? null,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    error: query.error,
    refetch: query.refetch,
  }
}

export function useMysqlConnectionStates(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const query = useQuery(
    mysqlConnectionStatesQueryOptions(projectId, databaseId),
  )
  return {
    states: query.data ?? [],
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  }
}

export function useMysqlConnectionApps(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const query = useQuery(
    mysqlConnectionAppsQueryOptions(projectId, databaseId),
  )
  return {
    apps: query.data ?? [],
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  }
}

export function useMysqlActiveConnections(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const query = useQuery(
    mysqlActiveConnectionsQueryOptions(projectId, databaseId),
  )
  return {
    connections: query.data ?? [],
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    error: query.error,
    refetch: query.refetch,
  }
}

export function useMysqlTableActivity(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const query = useQuery(mysqlTableActivityQueryOptions(projectId, databaseId))
  return {
    tables: query.data ?? [],
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  }
}

/**
 * Polls the MySQL metrics snapshot while the monitor view is open.
 * Used for overview KPIs and live health signals (not time-series charts).
 */
export function useMysqlMetricsSampling(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  options?: { enabled?: boolean; pollIntervalMs?: number },
) {
  const enabled = options?.enabled ?? true
  const pollIntervalMs = options?.pollIntervalMs ?? METRICS_POLL_INTERVAL_MS

  const query = useQuery({
    ...mysqlMetricsSnapshotQueryOptions(projectId, databaseId),
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

function mysqlActiveConnectionsQueryKey(
  projectId: string,
  databaseId: string,
) {
  return ['mysql', 'active-connections', projectId, databaseId] as const
}

async function refreshMysqlActiveConnections(
  queryClient: ReturnType<typeof useQueryClient>,
  projectId: string,
  databaseId: string,
) {
  await queryClient.refetchQueries({
    queryKey: mysqlActiveConnectionsQueryKey(projectId, databaseId),
  })
}

export function useCancelMysqlBackend(
  projectId: string,
  databaseId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (pid: number) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return executeMysqlDatabaseSql(
        projectId,
        databaseId,
        buildMysqlCancelBackendSql(pid),
      )
    },
    onSuccess: async () => {
      await refreshMysqlActiveConnections(queryClient, projectId, databaseId)
      toast.success('Query canceled')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) ?? 'Failed to cancel query')
    },
  })
}

export function useTerminateMysqlBackend(
  projectId: string,
  databaseId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (pid: number) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return executeMysqlDatabaseSql(
        projectId,
        databaseId,
        buildMysqlTerminateBackendSql(pid),
      )
    },
    onSuccess: async () => {
      await refreshMysqlActiveConnections(queryClient, projectId, databaseId)
      toast.success('Connection terminated')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) ?? 'Failed to terminate connection')
    },
  })
}

export function useTerminateMysqlIdleInTransaction(
  projectId: string,
  databaseId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return executeMysqlDatabaseSql(
        projectId,
        databaseId,
        MYSQL_TERMINATE_IDLE_IN_TRANSACTION_SQL,
      )
    },
    onSuccess: async () => {
      await refreshMysqlActiveConnections(queryClient, projectId, databaseId)
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
