import { useCallback, useEffect, useState } from 'react'
import { queryOptions, useQuery } from '@tanstack/react-query'
import { executePostgresDatabaseSql } from './postgres-databases'
import {
  POSTGRES_METRICS_CONNECTION_STATES_SQL,
  POSTGRES_METRICS_SNAPSHOT_SQL,
  POSTGRES_METRICS_TABLE_ACTIVITY_SQL,
} from '@/lib/postgres-metrics-sql'
import {
  appendPostgresMetricsSample,
  buildPostgresMetricsSample,
  parsePostgresConnectionStates,
  parsePostgresMetricsSnapshot,
  parsePostgresTableActivity,
  readPostgresMetricsSamples,
  type PostgresConnectionStateRow,
  type PostgresMetricsSample,
  type PostgresMetricsSnapshot,
  type PostgresTableActivityRow,
} from '@/lib/postgres-metrics'
import { DEFAULT_STALE_TIME } from './constants'

const METRICS_POLL_INTERVAL_MS = 60_000

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
    queryKey: ['postgres', 'metrics', 'connections', projectId, databaseId],
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
 * Polls metrics via the SQL API and appends rate samples to session storage
 * for time-series charts while the monitor view is open.
 */
export function usePostgresMetricsSampling(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  options?: { enabled?: boolean; pollIntervalMs?: number },
) {
  const enabled = options?.enabled ?? true
  const pollIntervalMs = options?.pollIntervalMs ?? METRICS_POLL_INTERVAL_MS
  const [samples, setSamples] = useState<PostgresMetricsSample[]>(() =>
    databaseId ? readPostgresMetricsSamples(databaseId) : [],
  )
  const [lastRecordedAt, setLastRecordedAt] = useState<number | null>(null)

  const recordSnapshot = useCallback(
    (snapshot: PostgresMetricsSnapshot | null) => {
      if (!databaseId || !snapshot) return
      const existing = readPostgresMetricsSamples(databaseId)
      const previous = existing[existing.length - 1] ?? null
      const sample = buildPostgresMetricsSample(snapshot, previous)
      const next = appendPostgresMetricsSample(databaseId, sample)
      setSamples(next)
      setLastRecordedAt(sample.timestamp)
    },
    [databaseId],
  )

  const {
    snapshot,
    isLoading,
    isFetching,
    error,
    refetch,
  } = usePostgresMetricsSnapshot(projectId, databaseId)

  useEffect(() => {
    if (!databaseId) return
    setSamples(readPostgresMetricsSamples(databaseId))
  }, [databaseId])

  useEffect(() => {
    if (!enabled || !snapshot) return
    recordSnapshot(snapshot)
  }, [enabled, snapshot, recordSnapshot])

  useEffect(() => {
    if (!enabled || !projectId || !databaseId) return
    const interval = window.setInterval(() => {
      void refetch()
    }, pollIntervalMs)
    return () => window.clearInterval(interval)
  }, [enabled, projectId, databaseId, pollIntervalMs, refetch])

  const refresh = useCallback(() => refetch(), [refetch])

  return {
    snapshot,
    samples,
    lastRecordedAt,
    isLoading,
    isFetching,
    error,
    refresh,
  }
}
