/**
 * React Query hooks for MySQL dedicated database backups.
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

export const MYSQL_BACKUPS_PAGE_SIZE = GRID_DEFAULT_PAGE_SIZE

export async function fetchMysqlBackupPolicies(
  projectId: string,
  databaseId: string,
): Promise<Models.BackupPolicyList> {
  return fetchDedicatedBackupPolicies(projectId, databaseId, 'mysql')
}

export async function fetchMysqlBackups(
  projectId: string,
  databaseId: string,
  page: number = 0,
  limit: number = MYSQL_BACKUPS_PAGE_SIZE,
): Promise<Models.DedicatedDatabaseBackupList> {
  if (!projectId || !databaseId) {
    return { backups: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  return projectSdk.mysql.listBackups({
    databaseId,
    queries: [
      Query.limit(limit),
      Query.offset(page * limit),
      Query.orderDesc('$createdAt'),
    ],
  })
}

export function mysqlBackupPoliciesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return dedicatedBackupPoliciesQueryOptions(
    projectId,
    databaseId,
    'mysql',
  )
}

export function mysqlBackupsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  page: number = 0,
  limit: number = MYSQL_BACKUPS_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: [
      'mysql-backups',
      'project',
      projectId,
      databaseId,
      page,
      limit,
    ],
    queryFn: () => fetchMysqlBackups(projectId!, databaseId!, page, limit),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function useMysqlBackupPolicies(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return useQuery(mysqlBackupPoliciesQueryOptions(projectId, databaseId))
}

export function useMysqlBackups(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  page: number = 0,
  limit: number = MYSQL_BACKUPS_PAGE_SIZE,
) {
  return useQuery(
    mysqlBackupsQueryOptions(projectId, databaseId, page, limit),
  )
}
