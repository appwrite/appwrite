/**
 * Mysql-native wrappers around dedicated-database replication hooks.
 * Prefer `dedicated-database-replication` for new call sites that are
 * engine-agnostic or product-owned.
 */

import {
  dedicatedDatabaseReplicasQueryOptions,
  fetchDedicatedDatabaseReplicas,
  useCreateDedicatedDatabaseFailover,
  useDedicatedDatabaseReplicas,
  createDedicatedDatabaseFailover,
} from './dedicated-database-replication'

const MYSQL_SOURCE = { type: 'engine' as const, engine: 'mysql' }

export async function fetchMysqlDatabaseReplicas(
  projectId: string,
  databaseId: string,
) {
  return fetchDedicatedDatabaseReplicas(projectId, databaseId, MYSQL_SOURCE)
}

export function mysqlDatabaseReplicasQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  enabled = true,
  refetchInterval?: number | false,
) {
  return dedicatedDatabaseReplicasQueryOptions(
    projectId,
    databaseId,
    MYSQL_SOURCE,
    enabled,
    refetchInterval,
  )
}

export function useMysqlDatabaseReplicas(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  enabled = true,
  refetchInterval?: number | false,
) {
  return useDedicatedDatabaseReplicas(
    projectId,
    databaseId,
    MYSQL_SOURCE,
    enabled,
    refetchInterval,
  )
}

export async function createMysqlDatabaseFailover(
  projectId: string,
  databaseId: string,
  targetReplicaId: string,
) {
  return createDedicatedDatabaseFailover(
    projectId,
    databaseId,
    MYSQL_SOURCE,
    targetReplicaId,
  )
}

export function useCreateMysqlDatabaseFailover(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return useCreateDedicatedDatabaseFailover(
    projectId,
    databaseId,
    MYSQL_SOURCE,
  )
}
