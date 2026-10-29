/**
 * Postgres-native wrappers around dedicated-database replication hooks.
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

const POSTGRES_SOURCE = { type: 'engine' as const, engine: 'postgresql' }

export async function fetchPostgresDatabaseReplicas(
  projectId: string,
  databaseId: string,
) {
  return fetchDedicatedDatabaseReplicas(projectId, databaseId, POSTGRES_SOURCE)
}

export function postgresDatabaseReplicasQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  enabled = true,
  refetchInterval?: number | false,
) {
  return dedicatedDatabaseReplicasQueryOptions(
    projectId,
    databaseId,
    POSTGRES_SOURCE,
    enabled,
    refetchInterval,
  )
}

export function usePostgresDatabaseReplicas(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  enabled = true,
  refetchInterval?: number | false,
) {
  return useDedicatedDatabaseReplicas(
    projectId,
    databaseId,
    POSTGRES_SOURCE,
    enabled,
    refetchInterval,
  )
}

export async function createPostgresDatabaseFailover(
  projectId: string,
  databaseId: string,
  targetReplicaId: string,
) {
  return createDedicatedDatabaseFailover(
    projectId,
    databaseId,
    POSTGRES_SOURCE,
    targetReplicaId,
  )
}

export function useCreatePostgresDatabaseFailover(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return useCreateDedicatedDatabaseFailover(
    projectId,
    databaseId,
    POSTGRES_SOURCE,
  )
}
