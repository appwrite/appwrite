/**
 * React Query hooks for PostgreSQL dedicated database backups.
 */

import { useQuery, queryOptions } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console' // pragma: allowlist secret
import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import { sdk } from '@/lib/appwrite/sdk' // pragma: allowlist secret
import { DEFAULT_STALE_TIME, GRID_DEFAULT_PAGE_SIZE } from './constants'
import {
  dedicatedBackupPoliciesQueryOptions,
  fetchDedicatedBackupPolicies,
} from './dedicated-database-backups'

export const POSTGRES_BACKUPS_PAGE_SIZE = GRID_DEFAULT_PAGE_SIZE

export async function fetchPostgresBackupPolicies(
  projectId: string,
  databaseId: string,
): Promise<Models.BackupPolicyList> {
  return fetchDedicatedBackupPolicies(projectId, databaseId, 'postgresql')
}

export async function fetchPostgresBackups(
  projectId: string,
  databaseId: string,
  page: number = 0,
  limit: number = POSTGRES_BACKUPS_PAGE_SIZE,
): Promise<Models.DedicatedDatabaseBackupList> {
  if (!projectId || !databaseId) {
    return { backups: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  return projectSdk.postgresql.listBackups({
    databaseId,
    queries: [
      Query.limit(limit),
      Query.offset(page * limit),
      Query.orderDesc('$createdAt'),
    ],
  })
}

export function postgresBackupPoliciesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return dedicatedBackupPoliciesQueryOptions(
    projectId,
    databaseId,
    'postgresql',
  )
}

export function postgresBackupsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  page: number = 0,
  limit: number = POSTGRES_BACKUPS_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: [
      'postgres-backups',
      'project',
      projectId,
      databaseId,
      page,
      limit,
    ],
    queryFn: () => fetchPostgresBackups(projectId!, databaseId!, page, limit),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function usePostgresBackupPolicies(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return useQuery(postgresBackupPoliciesQueryOptions(projectId, databaseId))
}

export function usePostgresBackups(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  page: number = 0,
  limit: number = POSTGRES_BACKUPS_PAGE_SIZE,
) {
  return useQuery(
    postgresBackupsQueryOptions(projectId, databaseId, page, limit),
  )
}
