/**
 * React Query hooks for dedicated database backups (Postgres / MySQL / Mongo).
 *
 * Native dedicated policies live on the engine APIs
 * (`postgresql|mysql|mongo.listBackupPolicies`), not on
 * `Models.Database.policies` from `console.listDatabases`.
 */

import { useQuery, queryOptions } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { dedicatedEngineService } from '@/lib/databases/dedicated-engine'
import { DEFAULT_STALE_TIME, GRID_DEFAULT_PAGE_SIZE } from './constants'

export const POSTGRES_BACKUPS_PAGE_SIZE = GRID_DEFAULT_PAGE_SIZE

function normalizeDedicatedEngine(
  engine: string | null | undefined,
): string {
  const normalized = (engine ?? '').toLowerCase().trim()
  if (normalized === 'mongodb' || normalized === 'mongo') return 'mongodb'
  if (normalized === 'mysql' || normalized === 'mariadb') return 'mysql'
  return 'postgresql'
}

/** Backup policies for a native dedicated database (any engine). */
export async function fetchDedicatedBackupPolicies(
  projectId: string,
  databaseId: string,
  engine?: string | null,
): Promise<Models.BackupPolicyList> {
  if (!projectId || !databaseId) {
    return { policies: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  return dedicatedEngineService(projectSdk, engine).listBackupPolicies({
    databaseId,
    queries: [Query.orderDesc('$createdAt')],
  })
}

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

export function dedicatedBackupPoliciesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  engine?: string | null,
) {
  const normalizedEngine = normalizeDedicatedEngine(engine)
  return queryOptions({
    queryKey: [
      'dedicated-backup-policies',
      'project',
      projectId,
      databaseId,
      normalizedEngine,
    ],
    queryFn: () =>
      fetchDedicatedBackupPolicies(projectId!, databaseId!, normalizedEngine),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
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

export function useDedicatedBackupPolicies(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  engine?: string | null,
) {
  return useQuery(
    dedicatedBackupPoliciesQueryOptions(projectId, databaseId, engine),
  )
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
